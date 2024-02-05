<?php

namespace App\Http\Controllers;

use App\Categories;
use App\Chat;
use App\Idea;
use App\Tag;
use App\Constant\Constants;
use App\Events\GroupEvent;
use App\Http\Helper\Helper;
use App\Http\Resources\IdeaResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Follow;
use App\Funding;
use App\Group;
use App\GroupUser;
use App\IdeaCategory;
use App\Http\Resources\IdeaZipcodeResource;
use App\Image;
use App\Invite;
use App\EmailNotificationContent;
use App\Notes;
use App\Notifications\IdeaMail;
use App\Revision;
use App\User;
use App\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IdeaController extends Controller
{

    public function list(Request $request)
    {
        $user = Auth::guard('api')->user();

        $statusArr = [
            // under review/pending
            Constants::IDEA_PENDING,
            // approved
            Constants::IDEA_APPROVED,
            // published
            Constants::IDEA_PUBLISHED,
            // Rejected
            Constants::IDEA_REJECTED,
            // Draft
            Constants::IDEA_DRAFT,
            // claimed
            Constants::IDEA_CLAIMED,
            // duplicate
            Constants::IDEA_DUPLICATE,
            // if user is inactive - and posts an idea
            Constants::IDEA_INACTIVE,

            Constants::IDEA_UNCLAIMED
        ];
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $claim_status_title = '';
        if ($request->filled('withTrash') && !empty($request->withTrash)) {
            $list = Idea::withTrashed();
        } else {
            $list = Idea::query();
        }
        $list = $list->where('status', '!=', Constants::IDEA_INACTIVE);

        if ($request->filled('filter_anonymus') && $request->filter_anonymus) {
            $list = $list->where("is_anonymous", 0);
        }
        $count = $list->count();
        $publishedCount = 0;
        $pendingReview = 0;
        $frontend = $request->filled('frontend') ? $request->frontend : false;
        if ($request->filled('status')) {
            // frontend, Admin, Media, Journalist to display sort by published date.

            if ($request->status == Constants::IDEA_REPORTING_PAUSED || $request->claimed_status == Constants::CLAIMED_IDEA_STATUS_INVESTIGATE) {
                $claimed_status = Constants::CLAIMED_IDEA_STATUS_INVESTIGATE;
                $claim_status_title  = 'Reporting Paused';
            } elseif ($request->status == Constants::IDEA_ANSWERED || $request->claimed_status == Constants::CLAIMED_IDEA_STATUS_EDIT) {
                $claimed_status = Constants::CLAIMED_IDEA_STATUS_EDIT;
                $claim_status_title  = 'Answered';
            } elseif ($request->status == Constants::IDEA_CLAIMED && $request->claimed_status == Constants::IDEA_CLAIMED) {
                $claimed_status = Constants::IDEA_CLAIMED;
                $claim_status_title  = 'Claimed';
            } elseif ($request->status == Constants::IDEA_REPORTING || $request->claimed_status == Constants::CLAIMED_IDEA_STATUS_RESEARCH) {
                $claimed_status = Constants::CLAIMED_IDEA_STATUS_RESEARCH;
                $claim_status_title  = 'Reporting';
            } else {
                $list = $list->where('status', $request->status);
            }
            if ($request->status == Constants::IDEA_PUBLISHED) {
               
                if (!$request->filled('sortField')) {
                    $list = $list->orderBy('published_date', 'DESC'); // order by recently published
                }
            }
        }
        if ($request->filled('Categories')) {
            $ideaId = IdeaCategory::where('categories_id', $request->Categories)->groupBy('idea_id')->pluck('idea_id')->toArray();
            $list = $list->whereIn('id',$ideaId);
        }
        if ($request->filled('month') && $request->filled('year')) {
            $month = Carbon::createFromDate($request->year, $request->month);
            $start = $month->startOfMonth()->toDateTimeString();
            $end = $month->endOfMonth()->toDateTimeString();
            $list->whereBetween('created_at', [
                $start,
                $end
            ]);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $list = $list->where(function ($query) use ($search) {
                $query->where('title', 'like', "%{$search}%")
                        ->orWhere('zipcode', 'like', "%{$search}%")
                        ->orWhere('details', 'like', "%{$search}%")
                        ->orWhereHas('createdBy', function($q) use ($search){
                            $q->where('name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('claimedBy', function($q) use ($search){
                            $q->where('name', 'like', "%{$search}%");
                        });
                });
            // $list = Idea::leftjoin('users','users.id','=','ideas.claimed_by')->leftjoin('users as contributor','contributor.id','=','ideas.created_by')->select('ideas.*','users.name','contributor.name')->where(function ($query) use ($search) {
            //     $query->where('ideas.title', 'like', "%{$search}%")
            //             ->orWhere('ideas.zipcode', 'like', "%{$search}%")
            //             ->orWhere('ideas.details', 'like', "%{$search}%")
            //             ->orWhere('users.name', 'like', "%{$search}%")
            //             ->orWhere('contributor.name', 'like', "%{$search}%");
                        
            // });

        }

        if ($request->filled('claimed_by')) {
            $list->where('claimed_by', $request->claimed_by);
        }
        if (!empty($claim_status_title) && in_array($request->status, $statusArr)) {
            $list = $list->where('status', $request->status);
        }
        if ($request->filled('claim_status_title') && !empty($request->claim_status_title)) {
            $list = $list->where('status_title', $claim_status_title);
        }

        // for recent list in frontend
        if ($user) {
            $inviteIdeas = array();
            $mediaHouseAlongData = false;
            if (!$frontend) {
                // if dashboard
                // get invite accepted ideas.
                $inviteIdeas = Invite::select('idea_id')
                ->where('user_id', $user->id)
                ->where('state_id', Constants::INVITE_ACCEPTED)
                ->pluck('idea_id')
                ->toArray();

                if ($user->isAdmin()) {
                    $publishedCount = Idea::where('status', Constants::IDEA_PUBLISHED)->count();
                    $pendingReview = Idea::where('status', Constants::IDEA_PENDING)->count();
                }
                if ($user->isCommUser()) { // and is Community User
                    $list->where('created_by', $user->id);
                }

                if ($user->isJournalist()) { // and is Journalist User
                    $list = $list->where('is_blocked', 0);
                    if ($request->filled('assigned_to') && !empty($request->assigned_to)) {
                        $list = $list->where('assigned_to', $user->id);
                    }
                    if ($request->filled('working_on')) {
                        $list = $list->where('assigned_to', $user->id)->orWhere('claimed_by', $user->id);
                        if (!empty($inviteIdeas)) {
                            $list = $list->orWhereIn('id', $inviteIdeas)->where('status', $request->status);
                        }
                    }
                }

               if($request->filled('type')) /*In progress ideas for media house*/
               {

                $reporting = '3';
                $reportingPause = '1';
                if($request->type=="organization" && $request->status=="")
                {
                    $list->where('claimed_by', $user->id)->where('status', '5')->where(function ($query) {
                     $query->where('claimed_status', '=', '3')
                     ->orWhere('claimed_status', '=', '1');
                      });        
                }
                elseif($request->type=="organization" && $request->status=="15")
                {
                   
                    $list->where('claimed_by', $user->id)->where('claimed_status', '3');
                }
                 elseif($request->type=="organization" && $request->status=="16")
                {
                 
                    $list->where('claimed_by', $user->id)->where('claimed_status', '1');
                }
               
               }

                if ($user->isMediaHouse()) {
                    $list = $list->where('is_blocked', 0);
                    if ($request->filled('allMediaClaimedIdeas') && $request->allMediaClaimedIdeas) {
                        $mediaHouseAlongData = true;
                    }
                }
                if ($request->filled('status')) {

                    // if status filter
                    // if (($request->status == Constants::IDEA_APPROVED) && $request->filled('mediaIdeasAll')) {
                    //     $company = $user->mediaHouseJournalists(); // get MH -> journalists
                    //     array_push($company, $user->id); // merge MH + Journalists
                    //     $list->whereIn('created_by', $company); // get MH + Journalists created Ideas
                    // }

                    if ($request->status == Constants::IDEA_CLAIMED || $request->status == Constants::IDEA_PUBLISHED) {

                     

                  if(!empty($inviteIdeas))
                        {

                          //  dd($list->toSql());
                                if ($user->isJournalist()) { //and if Journalist
                            if ($request->filled('assigned_to') && !empty($request->assigned_to)) {
                                $list = $list->where('assigned_to', $user->id);
                            } else {
                              

                                $list = $list->where(function($query) use ($inviteIdeas,$user){
                                $query->where('claimed_by', $user->id)
                                ->orWhereIn('ideas.id', $inviteIdeas);
                                });

                            }
                        }
                        if ($user->isMediaHouse()) {  //or Media house
                            $mediaHouseAlongData = true;
                        }
 
                        }
                        else
                        {
                               if ($user->isJournalist()) { //and if Journalist
                            if ($request->filled('assigned_to') && !empty($request->assigned_to)) {
                                $list = $list->where('assigned_to', $user->id);
                            } else {
                                $list = $list->where('claimed_by', $user->id);
                            }
                        }
                        if ($user->isMediaHouse()) {  //or Media house
                            $mediaHouseAlongData = true;
                        }

                        }

                    }
                }
                if ($mediaHouseAlongData) {
                    $company = $user->mediaHouseJournalists(); // get MH -> journalists
                    array_push($company, $user->id); // merge MH + Journalists

                    $list->whereIn('claimed_by', $company); // get MH + Journalists Ideas based on above status
                    if ($request->filled('assigned_to') && !empty($request->assigned_to)) {
                        $list = $list->orWhereIn('assigned_to', $company);
                    }
                }
            } else {
                $list = $list->where('status', '!=', Constants::IDEA_DUPLICATE);
                $list = $list->where('status', '!=', Constants::IDEA_PENDING);
            }
        } else {
            $list = $list->where('status', '!=', Constants::IDEA_DUPLICATE);
            $list = $list->where('status', '!=', Constants::IDEA_PENDING);
        }

        if ($request->filled('popular')) {
            $model = Follow::select('followable_id', DB::raw('COUNT(followable_id) AS occurrences'))
            ->where('followable_type', Idea::class)
            ->groupBy('followable_id')
            ->orderBy('occurrences', 'DESC')
            ->limit(20)
            ->pluck('followable_id');
            if (!empty($model)) {
                $list->whereIn('id', $model);
            }
        }
        if ($request->filled('claimed_status')) {
            $list->where('claimed_status', $request->claimed_status);
        }
        if ($request->filled('published_claimed_status')) {
            $list->whereIn('claimed_status', $request->published_claimed_status);
        }
        if (!empty($claimed_status)) {
            $list->where('claimed_status', $claimed_status);
        }
        if ($request->filled('created_by')) {
            $list->where('created_by', $request->created_by);
        }


        if ($request->filled('zipcode')) {
            $list->where('zipcode', $request->zipcode);
        }
        if ($request->filled('multiple_status')) {
            $list->whereIn('status', $request->multiple_status);
        }
        if ($request->filled('featured')) {
            $list->where('is_featured', Constants::STATE_ACTIVE);
        }
        $list->with('categories', 'claimedBy', 'tags', 'images', 'follows', 'assignedTo');

        if ($request->filled('for_home')) {
            $list = $list
            ->orderByRaw(
                "CASE
                WHEN status = 1 OR status = 2 OR status = 5 OR is_featured = 1
                THEN (
                CASE
                WHEN status = 1 AND approved_date >= updated_at
                THEN  approved_date
                WHEN status = 2 AND published_date >= updated_at AND published_date >= approved_date AND published_date >= claimed_date
                THEN  published_date
                WHEN status = 5 AND claimed_date >= updated_at AND claimed_date >= approved_date
                THEN  claimed_date
                WHEN is_featured = 1 AND featured_date >= updated_at AND featured_date >= approved_date
                THEN featured_date
                WHEN is_featured = 1 AND featured_date >= updated_at AND featured_date >= approved_date AND featured_date >= claimed_date
                THEN featured_date
                WHEN is_featured = 1 AND featured_date >= updated_at AND featured_date >= approved_date AND featured_date >= claimed_date AND featured_date >= published_date
                THEN featured_date
                END
                )
                END
                DESC"
            );
            $list = $list->where('is_blocked', '!=', Constants::BLOCKED)->take($limit)->get();
        } else {
            if ($request->filled('sortField') && $request->filled('sortOrder')) {
                if ($request->sortField == 'admin_status' ||  $request->sortField == 'claimed_status') {
                    $list = $list->orderBy('status_title', $request->sortOrder);
                } elseif ($request->sortField == 'created_by.name' || $request->sortField == 'contributor') {
                    $list = $list->join('users', 'ideas.created_by', '=', 'users.id')
                    ->orderBy('users.name', $request->sortOrder);
                } elseif ($request->sortField == 'title') {
                    $list = $list->orderBy($request->sortField, $request->sortOrder);
                }elseif ($request->sortField == 'claimed_by.name') {
                    $list = $list->join('users', 'ideas.claimed_by', '=', 'users.id')
                    ->orderBy('users.name', $request->sortOrder);
                }else {
                    $list = $list->orderBy($request->sortField, $request->sortOrder);
                }
            } else {
                $list = $list->latest('id');
            }
            if($frontend){
               $list = $list->where('is_blocked', '!=', Constants::BLOCKED)->paginate($limit);
            }else{
                $list = $list->paginate($limit);
            }
       }
       return $this->success([
        'publishedCount' => $publishedCount,
        'pendingReview' => $pendingReview,
        'allIdeas' => $count,
        'data' => IdeaResource::collection($list),
        'message' => 'Idea List fetched successfully',
    ]);
   }
   public function ideasWorkingOn(Request $request)
   {
    $user = Auth::user();
    $limit = $request->limit;
    $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;

        // $list = Idea::withTrashed();
    $list = Idea::query();
    if ($request->filled('working_on')) {
        $inviteIdeas = Invite::select('idea_id')
        ->where('user_id', $user->id)
        ->where('state_id', Constants::INVITE_ACCEPTED)
        ->orderBy('updated_at', 'ASC')
        ->pluck('idea_id')
        ->toArray();

        $list = $list->where('assigned_to', $user->id)->orWhere('claimed_by', $user->id);
        if (!empty($inviteIdeas)) {
            $list = $list->orWhereIn('id', $inviteIdeas)->where('status', Constants::IDEA_CLAIMED)
            ->orderByRaw("FIELD(id ," . implode(',', $inviteIdeas) . " ) DESC");
        }
    }
    $list->with('categories', 'claimedBy', 'tags', 'images', 'follows', 'assignedTo');
    $list = $list->paginate($limit);
    return $this->success([
        'data' => IdeaResource::collection($list),
        'message' => 'Idea List fetched successfully',
    ]);
}


    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|max:32|string',
            'details' =>  'required|string',
            'categories' => 'required',
            'status' => 'required|numeric',
            'images.*' => 'mimes:jpeg,jpg,x-png,png,pdf,xls,xlsx,doc,docx,mp3,mp4,pptx,ppt'
        ]);
        try {
            DB::beginTransaction();

            $user = Auth::guard('api')->user();
            if ($user) {
                $request['created_by'] = $user->id;
                if (!$request->filled('status')) {
                    $request['status'] = Constants::IDEA_PENDING;
                    $request['status_title'] = "Pending";
                }
            } else {
                $request->validate([
                    'created_by' => 'required'
                ]);
                $user = User::where('id', $request->created_by)->first();
                if (empty($user)) {
                    return $this->error('Provided user data is Invalid');
                }
                $request['status'] = Constants::IDEA_INACTIVE;
                $request['status_title'] = "Inactive";
            }
            $request['slug'] = $this->createSlug(Idea::class, $request->title);
            $idea = Idea::create($request->all());
            if (!empty($request->categories)) {
                $categoriesArr = explode(",", $request->categories);
                $categories = array_filter($categoriesArr);
                $idea->categories()->attach($categories);
                $idea->categories()->updateExistingPivot($categories[0], ['primary' => Constants::STATE_ACTIVE]);
            }
            if (!empty($request->tags)) {
                // tags -> mandatory - max 20
                $tags = explode(',', $request->tags);

                foreach ($tags as $tag) {
                    if (is_numeric($tag)) {
                        $tagId[] = $tag;
                    } elseif (is_string($tag)) {
                        $tagModel = Tag::firstOrCreate([
                            'title' => $tag
                        ]);
                        if ($tagModel->wasRecentlyCreated) {
                            $tagModel->update([
                                'slug' => $this->createSlug(Tag::class, $tagModel->title)
                            ]);
                        }
                        $tagId[] = $tagModel->id;
                    }
                }

                $idea->tags()->attach($tagId);
            }

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $key => $file) {
                    if ($key == 1) {
                        $featured = Constants::IS_FEATURED;
                    } else {
                        $featured = Constants::NOT_FEATURED;
                    }
                    $uploadedImage = Helper::saveImage($file, 'idea-images', $idea->id);
                    $image = new Image();
                    $image->name = $file->getClientOriginalName();
                    $image->featured = $featured;
                    $image->file_name =  $uploadedImage;
                    $image->extension = $file->getClientOriginalExtension();
                    if ($user) {
                        $image->user()->associate($user);
                    }
                    $idea->images()->save($image);
                }
            }
            $data = [
                'message' => "for admin|subadmin approval",
                'state_id' => 0,
                'type_id' => Constants::NOTIFICATION_IDEA_NEW,
                'notifiable_id' => $idea->id,
                'notifiable_type' => get_class($idea),
                'created_by_id' => $user->id,
                'roles' => [
                    Constants::TYPE_SUPER_ADMIN,
                    Constants::TYPE_ADMIN,
                    Constants::TYPE_SUB_ADMIN
                ]
            ];
            $this->sendIdeaMails($idea, Constants::NOTIFICATION_NEW_IDEA_SUBMIT_NOTIFY_ADMIN, false, false, false, null, null);
            $revision = [
                'user_id' => $user->id,
                'revisionable_id' => $idea->id,
                'revisionable_type' => get_class($idea),
                'key' => null,
                'old_value' => null,
                'new_value' => null,
                'key_string' => $user->name . " submitted the idea.",
                'type' => Constants::VERSION_IDEA
            ];
            Revision::create($revision);
            Helper::sendNotification($data);
            DB::commit();
            return $this->success([
                'data' => new IdeaResource($idea),
                'message' => 'Idea stored successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }

    public function sendIdeaMails($model, $status, $claimedStatus, $edited, $approved, $assignedTo, $claimedBy, $user=[],$original=[])
    {
        $url = Constants::FRONTEND_URL . "ideas/" . $model->slug;
        $heading = ' Idea : "' . $model->title . '" ';
        $second = '';
        $mname ='';
        $pmname='Freelancer';
        $name = null;
        $user = Auth::user();
        // to admin also
        $admin = User::whereIn('role_id', [Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN])->where('state_id', Constants::STATE_ACTIVE)->get();

/* if journalist claimed idea and he is under media house, mail should be send to media-house as well*/
        $mediaHouseData = "";
        $user = Auth::user();
        $userId = $user->media_house_id;
      
       if(!empty($userId))
       {
         $getMediaHouse = User::where("id",$userId)
        ->get();
       }
       else
       {
        $getMediaHouse = "";
       }
 
        if ($status == Constants::IDEA_UNCLAIMED) {
            //here
            $second = " Unclaimed Notes: " . $model->notes . " ";
            if ($user->isAdmin()) {
                if (!empty($assignedTo)) {
                    // to journalist
                    $mh_subject = " Idea : UnClaimed ";
                    $mh_first = " " . $user->name . ' has unclaimed story idea: "' . $model->title . '" ';
                    $mh_btnText = ' VIEW STORY IDEA ';
                    $this->customSendMail($assignedTo, (new IdeaMail($mh_subject, $heading,'Hello', $mh_first, $second, $url, $mh_btnText)));
                }
                if (!empty($claimedBy)) {
                    // to claimedby
                    $mh_subject = " Idea : UnClaimed ";
                    $mh_first = " " . $user->name . ' has unclaimed story idea: "' . $model->title . '" ';
                    $mh_btnText = ' VIEW STORY IDEA ';
                    $this->customSendMail($claimedBy, (new IdeaMail($mh_subject, $heading, 'Hello', $mh_first, $second, $url, $mh_btnText)));
                }
            }
            if ($user->isJournalist()) {
                // to admin
                if (!empty($admin)) {
                    $admin_subject = " Idea : UnClaimed ";
                    $admin_first = " " . $user->name . ' has unclaimed a story idea: "' . $model->title . '" ';
                    $admin_url = Constants::FRONTEND_URL . 'admin/idea/edit/' . $model->id;
                    $admin_btnText = ' REVIEW IDEA ';
                    foreach ($admin as $sendTo) {
                        $this->customSendMail($sendTo, (new IdeaMail($admin_subject, $heading, 'Hello', $admin_first, $second, $admin_url, $admin_btnText)));
                    }
                }
            }

            // to contributor
            $contri_sub = " Idea: UnClaimed ";
            $contri_first = " We’re writing to let you know that your Idea " . $model->title . "  is unclaimed by " . $user->name . " ";
            $contri_btnText = " GO TO STORY MOSAIC ";
            $this->customSendMail($model->createdBy, (new IdeaMail($contri_sub, $heading, 'Hello', $contri_first, $second, $url, $contri_btnText)));
        }

        if (($status == Constants::IDEA_CLAIMED) && ($claimedStatus == Constants::CLAIMED_IDEA_STATUS_RESEARCH)) {
            $journalist = $model->claimedBy;
            if (!empty($journalist)) {
                $Rjname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $journalist->slug . '">' . $journalist->name . '</a> ';
                if (!empty($journalist->media_house_id)) {
                 $mediaHouse = User::where('id', $journalist->media_house_id)->first();
                 if (!empty($mediaHouse)) {
                        // to media house
                    $name .= ' / ';
                    $Rmname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $mediaHouse->slug . '">' . $mediaHouse->name . '</a> ';
                }
                    // to contributor
                $ideaReportingEmailContent = EmailNotificationContent::where(['slug'=>'idea-reporting', 'user_type'=>'Contributor', 'status'=>Constants::STATE_ACTIVE])->first();
                $contri_sub = " Idea: Reporting ";
                $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaReportingEmailContent->heading);
                $contri_first = str_replace(["<JOURNALIST_USERNAME>", "<MEDIA_HOUSE_NAME>", "<IDEA_TITLE>"], [$Rjname, $Rmname, $model->title], $ideaReportingEmailContent->content);
                $contri_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaReportingEmailContent->button_name);
                $this->customSendMail($model->createdBy, (new IdeaMail($contri_sub, $heading, 'Hello', $contri_first, $second, $url, $contri_btnText)));

                $model->update([
                    'claimed_mail_sent' => 1
                ]);
            }
        }      

                    if($original['claimed_status']!=Constants::CLAIMED_IDEA_STATUS_RESEARCH) /* this check is to send notifiction one time from another idea status */
                               {
                             
                                $getContributorId  = idea::where('id', $model->id)->select('created_by')->first();
                                $contributorId = $getContributorId->created_by;
                                
                                $data1['notifiable_id'] = $model->id;
                                $data1['notifiable_type'] =  get_class($model);
                                $data1['created_by_id'] = $user->id;
                                $data1['state_id'] = 0;
                                $data1['roles'] = [
                                Constants::TYPE_JOURNALIST
                                ];
                                $data1['message'] = "Idea Reported By Journalist";
                                $data1['type_id'] = Constants::NOTIFICATION_IDEA_REPORTED_BY_JOURNALIST;
                                $data1['user_id'] = $contributorId;

                                $this->sendNotificationToContributor($data1);
                                

                            }

     }


    if (($status == Constants::IDEA_CLAIMED)&& ($claimedStatus == Constants::CLAIMED_IDEA_STATUS_INVESTIGATE)) {
        $journalist = $model->claimedBy;
        if (!empty($journalist)) {
            $rpjname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $journalist->slug . '">' . $journalist->name . '</a> ';
            if (!empty($journalist->media_house_id)) {
             $mediaHouse = User::where('id', $journalist->media_house_id)->first();
             if (!empty($mediaHouse)) {
                        // to media house
                $name .= ' / ';
                $rpmname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $mediaHouse->slug . '">' . $mediaHouse->name . '</a> ';
            }
                    // to contributor
            $ideaReportingPausedEmailContent = EmailNotificationContent::where(['slug'=>'idea-reporting-paused', 'user_type'=>'Contributor', 'status'=>Constants::STATE_ACTIVE])->first();
            $contri_sub = " Idea: Reporting Paused ";
            $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaReportingPausedEmailContent->heading);
            $contri_first = str_replace(["<JOURNALIST_USERNAME>", "<MEDIA_HOUSE_NAME>", "<IDEA_TITLE>"], [$rpjname, $rpmname, $model->title], $ideaReportingPausedEmailContent->content);
            $contri_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaReportingPausedEmailContent->button_name);
            $this->customSendMail($model->createdBy, (new IdeaMail($contri_sub, $heading, 'Hello', $contri_first, $second, $url, $contri_btnText)));

            $model->update([
                'claimed_mail_sent' => 1
            ]);
        }
    }

                       if($original['claimed_status']!=Constants::CLAIMED_IDEA_STATUS_INVESTIGATE) 
                               {
                             
                                $getContributorId  = idea::where('id', $model->id)->select('created_by')->first();
                                $contributorId = $getContributorId->created_by;
                                $data1['notifiable_id'] = $model->id;
                                $data1['notifiable_type'] =  get_class($model);
                                $data1['created_by_id'] = $user->id;
                                $data1['state_id'] = 0;
                                $data1['roles'] = [
                                Constants::TYPE_JOURNALIST
                                ];
                                $data1['message'] = "Idea Reporting Pause By Journalist";
                                $data1['type_id'] = Constants::NOTIFICATION_IDEA_REPORTING_PAUSE_BY_JOURNALIST;
                                $data1['user_id'] = $contributorId;

                                $this->sendNotificationToContributor($data1);
                                

                            }

}
if (($status == Constants::IDEA_CLAIMED) && ($claimedStatus == Constants::CLAIMED_IDEA_STATUS_EDIT)) {
    $journalist = $model->claimedBy;
    if (!empty($journalist)) {
        $Ajname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $journalist->slug . '">' . $journalist->name . '</a> ';
        if (!empty($journalist->media_house_id)) {
         $mediaHouse = User::where('id', $journalist->media_house_id)->first();
         if (!empty($mediaHouse)) {
                        // to media house
            $name .= ' / ';
            $Amname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $mediaHouse->slug . '">' . $mediaHouse->name . '</a> ';
        }
                    // to contributor
        $ideaAnsweredEmailContent = EmailNotificationContent::where(['slug'=>'idea-answered', 'user_type'=>'Contributor', 'status'=>Constants::STATE_ACTIVE])->first();
        $contri_sub = " Idea: Answered ";
        $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaAnsweredEmailContent->heading);
        $contri_first = str_replace(["<JOURNALIST_USERNAME>", "<MEDIA_HOUSE_NAME>", "<IDEA_TITLE>"], [$Ajname, $Amname, $model->title], $ideaAnsweredEmailContent->content);
        $contri_btnText =  str_replace("<IDEA_TITLE>", "$model->title", $ideaAnsweredEmailContent->button_name);
        $this->customSendMail($model->createdBy, (new IdeaMail($contri_sub, $heading, 'Hello', $contri_first, $second, $url, $contri_btnText)));

        $model->update([
            'claimed_mail_sent' => 1
        ]);
    }
  }

                       if($original['claimed_status']!=Constants::CLAIMED_IDEA_STATUS_EDIT) 
                               {
                             
                                $getContributorId  = idea::where('id', $model->id)->select('created_by')->first();
                                $contributorId = $getContributorId->created_by;
                                $data1['notifiable_id'] = $model->id;
                                $data1['notifiable_type'] =  get_class($model);
                                $data1['created_by_id'] = $user->id;
                                $data1['state_id'] = 0;
                                $data1['roles'] = [
                                Constants::TYPE_JOURNALIST
                                ];
                                $data1['message'] = "Idea Reporting Pause By Journalist";
                                $data1['type_id'] = Constants::NOTIFICATION_IDEA_ANSWERED_BY_JOURNALIST;
                                $data1['user_id'] = $contributorId;

                                $this->sendNotificationToContributor($data1);
                                

                            }

}









        // contributor
        // on claiming of idea
if (($status == Constants::IDEA_CLAIMED) && (empty($model->claimed_mail_sent))) {
            // and to media house if journalist
    $journalist = $model->claimedBy;
    if (!empty($journalist)) {
        $jname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $journalist->slug . '">' . $journalist->name . '</a> ';
        if (!empty($journalist->media_house_id)) {
            $mediaHouse = User::where('id', $journalist->media_house_id)->first();
            if (!empty($mediaHouse)) {
                        // to media house
                $mname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $mediaHouse->slug . '">' . $mediaHouse->name . '</a> ';
                $ideaClaimedEmailContentMedia = EmailNotificationContent::where(['slug'=>'idea-claimed', 'user_type'=>'Media House', 'status'=>Constants::STATE_ACTIVE])->first();
                $mh_subject = " Media Organization: Idea : Claimed ";
                $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaClaimedEmailContentMedia->heading);
                $mh_first = str_replace(["<JOURNALIST_USERNAME>", "<MEDIA_HOUSE_NAME>", "<IDEA_TITLE>"], [$jname, $mname, $model->title], $ideaClaimedEmailContentMedia->content);
                $mh_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaClaimedEmailContentMedia->button_name);
                $this->customSendMail($mediaHouse, (new IdeaMail($mh_subject, $heading, 'Hello', $mh_first, $second, $url, $mh_btnText)));
            }
        }

                // to admin
        if (!empty($admin)) {
            $ideaClaimedEmailContentAdmin = EmailNotificationContent::where(['slug'=>'idea-claimed', 'user_type'=>'Admin', 'status'=>Constants::STATE_ACTIVE])->first();
            $admin_subject = " Admin: Idea : Claimed ";
            $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaClaimedEmailContentAdmin->heading);
            $admin_first = str_replace(["<JOURNALIST_USERNAME>", "<MEDIA_HOUSE_NAME>", "<IDEA_TITLE>"], [$jname, $mname, $model->title], $ideaClaimedEmailContentAdmin->content);
            $admin_url = Constants::FRONTEND_URL . 'admin/idea/edit/' . $model->id;
            $admin_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaClaimedEmailContentAdmin->button_name);
            foreach ($admin as $sendTo) {
                $this->customSendMail($sendTo, (new IdeaMail($admin_subject, $heading, 'Hello', $admin_first, $second, $admin_url, $admin_btnText)));
            }
            if(!empty($getMediaHouse))
            {
              $mediaHouseSubject = "Organisation: Idea : Claimed "; 
            foreach ($getMediaHouse as $sendTo) {
            $this->customSendMail($sendTo, (new IdeaMail($admin_subject, $heading, 'Hello', $admin_first, $second, $admin_url, $admin_btnText)));
            }
              
              
            }
        }
                // to contributor
        $ideaClaimedEmailContent = EmailNotificationContent::where(['slug'=>'idea-claimed', 'user_type'=>'Contributor', 'status'=>Constants::STATE_ACTIVE])->first();
        $contri_sub = " Idea: Claimed ";
        $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaClaimedEmailContent->heading);
        $contri_first = str_replace(["<JOURNALIST_USERNAME>","<MEDIA_HOUSE_NAME>"], [$jname, $mname], $ideaClaimedEmailContent->content);
        $contri_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaClaimedEmailContent->button_name);
        $this->customSendMail($model->createdBy, (new IdeaMail($contri_sub, $heading, 'Hello', $contri_first, $second, $url, $contri_btnText)));

        $model->update([
            'claimed_mail_sent' => 1
        ]);
    }
            // followers
    if (!empty($model->follows)) {
        $url = Constants::FRONTEND_URL . "/ideas/" . $model->slug;
        $subject = " Idea Claimed ";
        $btnText = " VIEW IDEA ";
        $notes = '';
        $first = " " . $user->name . " has Claimed a story idea: '" . $model->title . "' you are recieving mail as you are following this Idea.  ";
        foreach ($model->follows as $follow) {
            if (!empty($follow) && !empty($follow->user)) {
                $this->customSendMail($follow->user, (new IdeaMail(
                    $subject,
                    $first,
                    'Hello',
                    $first,
                    $notes,
                    $url,
                    $btnText
                )));
            }
        }
    }
}
        // Idea Published
if (($status == Constants::IDEA_PUBLISHED) && (empty($model->published_mail_sent))) {
    $journalist = $model->claimedBy;
    if (!empty($journalist)) {
        $pjname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $journalist->slug . '">' . $journalist->name . '</a> ';
        if (!empty($journalist->media_house_id)) {
            $mediaHouse = User::where('id', $journalist->media_house_id)->first();
            if (!empty($mediaHouse)) {
                        // to media house
                $ideaPublishedEmailContentMedia = EmailNotificationContent::where(['slug'=>'idea-published', 'user_type'=>'Media House', 'status'=>Constants::STATE_ACTIVE])->first();
                $name .= ' / ';
                $pmname = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $mediaHouse->slug . '">' . $mediaHouse->name . '</a> ';
                $mh_subject = " Media Organization: Idea : Published ";
                $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentMedia->heading);
                $mh_first = str_replace(["<JOURNALIST_USERNAME>", "<MEDIA_HOUSE_NAME>", "<IDEA_TITLE>"], [$pjname, $pmname, $model->title], $ideaPublishedEmailContentMedia->content);
                $mh_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentMedia->button_name);
                $this->customSendMail($mediaHouse, (new IdeaMail($mh_subject, $heading, 'Hello', $mh_first, $second, $url, $mh_btnText)));
            }
        }

                // to admin
        if (!empty($admin)) {
            $ideaPublishedEmailContentAdmin = EmailNotificationContent::where(['slug'=>'idea-published', 'user_type'=>'Admin', 'status'=>Constants::STATE_ACTIVE])->first();
            $admin_subject = " Admin: Idea : Published ";
            $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentAdmin->heading);
            $admin_first = str_replace(["<JOURNALIST_USERNAME>", "<MEDIA_HOUSE_NAME>", "<IDEA_TITLE>"], [$pjname, $pmname, $model->title], $ideaPublishedEmailContentAdmin->content);
            $admin_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentAdmin->button_name);
            $admin_url = Constants::FRONTEND_URL . 'admin/idea/edit/' . $model->id;
            foreach ($admin as $sendTo) {
                $this->customSendMail($sendTo, (new IdeaMail($admin_subject, $heading, 'Hello', $admin_first, $second, $url, $admin_btnText)));
            }

            if(!empty($getMediaHouse))
            {
              $mediaHouseSubject = "Organisation: Idea : Claimed "; 
            foreach ($getMediaHouse as $sendTo) {
            $this->customSendMail($sendTo, (new IdeaMail($admin_subject, $heading, 'Hello', $admin_first, $second, $admin_url, $admin_btnText)));
            }
              
              
            }
        }

                // to contributor
        $ideaPublishedEmailContent = EmailNotificationContent::where(['slug'=>'idea-published', 'user_type'=>'Contributor', 'status'=>Constants::STATE_ACTIVE])->first();
        $contri_sub = " Idea: Published ";
        $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContent->heading);
        $contri_first = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContent->content);
        $contri_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContent->button_name);
        $this->customSendMail($model->createdBy, (new IdeaMail($contri_sub, $heading, 'Hello', $contri_first, $second, $url, $contri_btnText)));

                // to followers of this idea
        $ideaPublishedEmailContentFollower = EmailNotificationContent::where(['slug'=>'idea-published', 'user_type'=>'Follower', 'status'=>Constants::STATE_ACTIVE])->first();
        $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentFollower->heading);
        $follower_first = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentFollower->content);
        $follower_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentFollower->button_name);
        $followers = $model->follows;
        if (!empty($followers)) {
            foreach ($followers as $follower) {
                $this->customSendMail($follower->user, (new IdeaMail($contri_sub, $heading, 'Hello', $follower_first, $second, $url, $follower_btnText)));
            }
        }

        $model->update([
            'published_mail_sent' => 1
        ]);
    }
            // followers
    if (!empty($model->follows)) {
        $ideaPublishedEmailContentFollow = EmailNotificationContent::where(['slug'=>'idea-published', 'user_type'=>'Follow', 'status'=>Constants::STATE_ACTIVE])->first();
        $url = Constants::FRONTEND_URL . "/ideas/" . $model->slug;
        $subject = " Idea Published  ";
        $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentFollow->heading);
        $btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaPublishedEmailContentFollow->button_name);
        $notes = "";
        $first = str_replace(["<JOURNALIST_USERNAME>", "<IDEA_TITLE>"], ["$user->name", "$model->title"], $ideaPublishedEmailContentFollow->content);
        foreach ($model->follows as $follow) {
            if (!empty($follow) && !empty($follow->user)) {
                $this->customSendMail($follow->user, (new IdeaMail(
                    $subject,
                    $first,
                    'Hello',
                    $first,
                    $notes,
                    $url,
                    $btnText
                )));
            }
        }
    }
}
        // if idea is marked duplicate
if ($status == Constants::IDEA_DUPLICATE) {
            // to contributor
    $ideaDuplicateEmailContent = EmailNotificationContent::where(['slug'=>'idea-duplicate', 'user_type'=>'Contributor', 'status'=>Constants::STATE_ACTIVE])->first();
    $contri_sub = " Idea: Duplicate ";
    $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaDuplicateEmailContent->heading);
    $contri_first = str_replace("<IDEA_TITLE>", "$model->title", $ideaDuplicateEmailContent->content);
    $second = " " . $model->duplicate_text . " ";
    $contri_btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaDuplicateEmailContent->button_name);
    $this->customSendMail($model->createdBy, (new IdeaMail($contri_sub, $heading, 'Hello', $contri_first, $second, $url, $contri_btnText)));
}

        // Edit of Original Idea - left
if ($status == Constants::NOTIFICATION_NEW_IDEA_SUBMIT_NOTIFY_ADMIN) {
    if (!empty($admin)) {
        $adminEmailContent = EmailNotificationContent::where(['slug'=>'new-idea', 'user_type'=>'Admin', 'status'=>Constants::STATE_ACTIVE])->first();
        $subject = " Idea : Submitted ";
        $heading = str_replace("<IDEA_TITLE>", "$model->title", $adminEmailContent->heading);
        $first = str_replace("<IDEA_TITLE>", "$model->title", $adminEmailContent->content);
        $url = Constants::FRONTEND_URL . 'admin/idea/edit/' . $model->id;
        $btnText = str_replace("<IDEA_TITLE>", "$model->title", $adminEmailContent->button_name);
        foreach ($admin as $sendTo) {
            $this->customSendMail($sendTo, (new IdeaMail($subject, $heading, 'Hello', $first, $second, $url, $btnText)));
        }
    }
}

$sendContriMail = false;
if ($approved) {
    switch ($status) {
        case Constants::IDEA_APPROVED:
        $ideaApprovedEmailContent = EmailNotificationContent::where(['slug'=>'idea-approved', 'user_type'=>'Contributor', 'status'=>Constants::STATE_ACTIVE])->first();
        $subject = " Idea : Approved ";
        $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaApprovedEmailContent->heading);
        $first = str_replace("<IDEA_TITLE>", "$model->title", $ideaApprovedEmailContent->content);
        $second = '';
        $btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaApprovedEmailContent->button_name);
        $sendContriMail = true;
        break;
        case Constants::IDEA_REJECTED:
        $ideaRejectedEmailContent = EmailNotificationContent::where(['slug'=>'idea-rejected', 'user_type'=>'Contributor', 'status'=>Constants::STATE_ACTIVE])->first();
        $subject = " Idea : Rejected ";
        $heading = str_replace("<IDEA_TITLE>", "$model->title", $ideaRejectedEmailContent->heading);
        $first = str_replace(["<IDEA_TITLE>","<FAQ_LINK>"], ["$model->title", "<a href='" .  Constants::FRONTEND_URL . 'faq' . "'> go here. </a>"], $ideaRejectedEmailContent->content);
        $second = " Review- " . $model->rejected_text . " ";
        $btnText = str_replace("<IDEA_TITLE>", "$model->title", $ideaRejectedEmailContent->button_name);
        $sendContriMail = true;
                    //     - Followers of the idea
        if (!empty($model->follows)) {
            $urlfollows = Constants::FRONTEND_URL . "/ideas/" . $model->slug;
            $subjectfollows = " Folling Idea : Rejected ";
            $firstfollows = " Idea " . $model->title . " has been rejected by admin ";
            $secondfollows = " Review- " . $model->rejected_text . " ";
            $btnTextfollows = " VIEW IDEA ";
            foreach ($model->follows as $follow) {
                if (!empty($follow) && !empty($follow->user)) {
                    $this->customSendMail($follow->user, (new IdeaMail(
                        $subjectfollows,
                        $firstfollows,
                        'Hello',
                        $firstfollows,
                        $secondfollows,
                        $urlfollows,
                        $btnTextfollows
                    )));
                }
            }
        }
        break;
        default:
        break;
    }
}

if ($sendContriMail) {
    $this->customSendMail($model->createdBy, (new IdeaMail($subject, $heading, 'Hello', $first, $second, $url, $btnText)));
}

if ($edited) {
    $subject = " Idea : Changes ";
    $first = " Changes have been done on your Idea. ";
    $btnText = ' VIEW IDEA ';
    $url = Constants::FRONTEND_URL . 'contributor/idea/edit/' . $model->id;
    $this->customSendMail($model->createdBy, (new IdeaMail($subject, $heading, 'Hello', $first, $second, $url, $btnText)));
}
return true;
}

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {

        $user = Auth::guard('api')->user();
        $idea = Idea::with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows', 'assignedTo')->findOrFail($id);
        if ($idea->status == Constants::IDEA_CLAIMED || $idea->status == Constants::IDEA_PUBLISHED || $user->role_id == Constants::TYPE_COMMUNITY_USER) {
            $pass = $idea->checkWhoClaimedAndPublishIdea();
            if (!$pass) {
                return $this->failed("You have no access to this Idea.");
            }
        }
        return $this->success([
            'data' => new IdeaResource($idea),
            'message' => 'Idea Fetch Successfully.'
        ]);
       }
       catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function showSlug(Request $request, $slug)
    {
        $published = false;
        if ($request->filled('original')) {
            if (!$request->original) {
                $published = true;
                $idea = Idea::where('published_slug', $slug);
            } else {
                $idea = Idea::where('slug', $slug);
            }
        } else {
            $idea = Idea::where('slug', $slug);
        }
        $idea = $idea->with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows', 'assignedTo')->first();
        if(!isset($idea->is_blocked))
        {
            return $this->failed("This Idea is blocked.");
        }
        if ($idea->is_blocked == Constants::BLOCKED) {
            return $this->failed("This Idea is blocked.");
        }
        if (!empty($idea)) {
            if (!$idea->viewPendingRejectedIdeaAccess()) {
                return $this->failed('You don\'t have access to this idea.');
            }
            $array=[
                'published' =>  $published,
                'data' => new IdeaResource($idea),
                'message' => 'Idea Fetch Successfully.'
            ];
            foreach ($array['data']['fundings'] as $key => $value) {
                $user_data = User::where('id', $array['data']['fundings'][$key]->user_id)->withTrashed()->first();
                $array['data']['fundings'][$key]->user_name=$user_data->name;
            }
            return $this->success($array);
        }
        return $this->failed('Idea does not exists!!!');
    }

    public function showPublishSlug($slug)
    {
        $idea = Idea::where('publish_slug', $slug)->with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows', 'assignedTo')->first();
        if (!empty($idea)) {
            return $this->success([
                'data' => new IdeaResource($idea),
                'message' => 'Publish Idea Fetch Successfully.'
            ]);
        }
        return $this->failed('Idea does not exists!!!');
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'details' =>  'required|string',
            'zipcode' => 'required|max:5',
            'categories' => 'required',
            'status' => 'required|numeric'
        ]);
        if ($request->status == Constants::IDEA_PUBLISHED) {
            if ($user->role_id != Constants::TYPE_SUPER_ADMIN || $user->role_id != Constants::TYPE_ADMIN || $user->role_id != Constants::TYPE_SUB_ADMIN) {
                $request->validate([
                    'publish_url' =>  'required|string',
                    'publish_details' => 'required|string'
                ]);
            }
        }
        if ($request->claimed_status == Constants::CLAIMED_IDEA_STATUS_EDIT) {
            if ($user->role_id != Constants::TYPE_SUPER_ADMIN || $user->role_id != Constants::TYPE_ADMIN || $user->role_id != Constants::TYPE_SUB_ADMIN) {
                $request->validate([
                    'publish_details' => 'required|string'
                ]);
            }
        }
        $idea = Idea::findOrFail($request->id);
        $original = $idea->getOriginal();

        $pass = false;
        $approved = false;
        $claimedBy = null;
        $assignedTo = null;
        $edited = false;


        if ($idea->is_blocked == Constants::BLOCKED) {
            return $this->failed("This Idea is blocked.");
        }
        if ($idea->status == Constants::IDEA_CLAIMED || $idea->status == Constants::IDEA_PUBLISHED || $user->role_id == Constants::TYPE_COMMUNITY_USER) {
            $pass = $idea->checkWhoClaimedAndPublishIdea();
            if (!$pass) {
                return $this->failed("You have no access to this Idea.");
            }
        }
        try {
            DB::beginTransaction();
            $idea['title'] = $request->title;
            $idea['details'] = $request->details;
            $idea['status'] = $request->status;
            $idea['zipcode'] = $request->zipcode;
            //if admin marks a Idea duplicate
            // if status == duplicate
            if ($request->filled('duplicate_text')) {
                $idea['duplicate_text'] = $request->duplicate_text;
            }
            if ($request->filled('rejected_text')) {
                $idea['rejected_text'] = $request->rejected_text;
            }

            if (!empty($request->categories)) {
                $initialcatArrIds = explode(',', $request->categories);
                $categoryIds = Categories::find($initialcatArrIds)->pluck('id')->toArray();
                $idea->categories()->sync($categoryIds);
                if (!empty($categoryIds)) {
                    $primaryCategory = $idea->categories()->select('categories_id')->where('primary', 1)->first();
                    if (!empty($primaryCategory) && (!in_array($primaryCategory->categories_id, $initialcatArrIds))) {
                        $idea->categories()->updateExistingPivot($categoryIds[0], ['primary' => 1]);
                    } else {
                        $idea->categories()->updateExistingPivot($categoryIds[0], ['primary' => 1]);
                    }
                }
            }
            if (!empty($request->tags)) {
                $tags = explode(',', $request->tags);
                foreach ($tags as $tag) {
                    if (is_numeric($tag)) {
                        $tagId[] = $tag;
                    } elseif (is_string($tag)) {
                        $tagModel = Tag::firstOrCreate([
                            'title' => $tag
                        ]);
                        if ($tagModel->wasRecentlyCreated) {
                            $tagModel->update([
                                'slug' => $this->createSlug(Tag::class, $tagModel->title)
                            ]);
                        }
                        $tagId[] = $tagModel->id;
                    }
                }
                $idea->tags()->sync($tagId);
            }
            $featuredImage = Image::where('imageable_id', $idea->id)
            ->where('imageable_type', Idea::class)
            ->where('featured', Constants::IS_FEATURED)->first();

            if ($request->filled('remove_image') && ($request->remove_image == 1)) {
                if (!empty($featuredImage)) {
                    Helper::removeImage($featuredImage->file_name);
                    $featuredImage->delete();
                    $featuredImage = null;
                }
            }

            if ($request->hasFile('featured_image')) {
                $request->validate([
                    'featured_image' => 'mimes:jpeg,jpg,png,gif',
                ]);
                $uploadedImage = Helper::saveImage($request->featured_image, 'idea-images', $idea->id);
                if (!empty($featuredImage)) {
                    Helper::removeImage($featuredImage->file_name);
                    $featuredImage->update([
                        'name' => $request->featured_image->getClientOriginalName(),
                        'featured' => Constants::IS_FEATURED,
                        'file_name' => $uploadedImage,
                        'extension' => $request->featured_image->getClientOriginalExtension()
                    ]);
                } else {
                    $image = new Image();
                    $image->name = $request->featured_image->getClientOriginalName();
                    $image->featured = Constants::IS_FEATURED;
                    $image->file_name =  $uploadedImage;
                    if ($user) {
                        $image->user()->associate($user);
                    }
                    $idea->images()->save($image);
                }
            }

            if ($request->status == Constants::IDEA_CLAIMED && (empty($idea->claimed_date))) {
                $idea['claimed_date'] = Carbon::now()->toDateTimeString();
                $idea['claimed_by'] = $user->id;
                $idea['claimed_status'] = Constants::IDEA_CLAIMED;
            }
            if($user->role_id == Constants::TYPE_SUPER_ADMIN || $user->role_id == Constants::TYPE_ADMIN || $user->role_id == Constants::TYPE_SUB_ADMIN){
                $idea['is_anonymous'] = $request->is_anonymous;
            }
            if ($request->filled('assigned_journalist') && !empty($request->assigned_journalist)) {
                if ($request->filled('assigned_to') && !empty($request->assigned_to)) {
                    $idea['assigned_to'] = $request->assigned_to;
                }
            }
            if ($request->status == Constants::IDEA_PUBLISHED) {
                if ($user->role_id != Constants::TYPE_SUPER_ADMIN || $user->role_id != Constants::TYPE_ADMIN || $user->role_id != Constants::TYPE_SUB_ADMIN) {
                    $request->validate([
                        'publish_url' =>  'required|string',
                        'publish_details' => 'required|string'
                    ]);
                }
                $idea['publish_title'] = $idea->publish_title;
                $idea['publish_url'] = $request->publish_url;
                $idea['publish_details'] = $request->publish_details;
                $idea['claimed_status'] = Constants::CLAIMED_IDEA_STATUS_PUBLISH;
                $idea['published_date'] = Carbon::now()->toDateTimeString();
            }
            if ($request->claimed_status == Constants::CLAIMED_IDEA_STATUS_EDIT) {
                if ($user->role_id != Constants::TYPE_SUPER_ADMIN || $user->role_id != Constants::TYPE_ADMIN || $user->role_id != Constants::TYPE_SUB_ADMIN) {
                    $request->validate([
                        'publish_details' => 'required|string'
                    ]);
                }
                $idea['publish_details'] = $request->publish_details;
            }

            if ($request->title != $idea->getOriginal('title')) {
                $idea['slug'] = $this->createSlug(Idea::class, $request->title, $idea->id);
            }

            if ($request->filled('publish_title')) {
                if ($request->publish_title != $idea->getOriginal('publish_title')) {
                    $idea['published_slug'] = $this->createSlug(Idea::class, $request->publish_title, $idea->id, 'published_slug');
                }
            }

            if ($request->filled('internal_remarks') && !empty($request->internal_remarks)) {
                if ($request->internal_remarks != $original['internal_remarks']) {
                    // add notes here in Notes model
                    Notes::create([
                        'noteable_id' => $idea->id,
                        'noteable_type' => get_class($idea),
                        'notes' => $request->internal_remarks,
                        'user_id' => $user->id
                    ]);
                }
                $idea['internal_remarks'] = $request->internal_remarks;
            }

            if ($request->status == Constants::IDEA_APPROVED && empty($idea->approved_date)) {

                $idea['approved_date'] = Carbon::now()->toDateTimeString();
                $approved = true;

                $getContributorId = $users = idea::where('id', $idea->id)->select('created_by')->first();
                $contributorId = $getContributorId->created_by;

                $data1['notifiable_id'] = $idea->id;
                $data1['notifiable_type'] =  get_class($idea);
                $data1['created_by_id'] = $user->id;
                $data1['state_id'] = 0;
                $data1['roles'] = [
                Constants::TYPE_SUPER_ADMIN,
                Constants::TYPE_ADMIN,
                Constants::TYPE_SUB_ADMIN
                ];
                $data1['message'] = "Idea Approved By SuperAdmin";
                $data1['type_id'] = Constants::NOTIFICATION_IDEA_APPROVED;
                $data1['user_id'] = $contributorId;

                $this->sendNotificationToContributor($data1);
            }

            if ($request->status == Constants::IDEA_REJECTED) {
                $approved = true;
                $idea['claimed_status'] = Constants::IDEA_PENDING;
            }
            

            if($request->filled('claim_type'))
            {
                $idea['status'] = Constants::IDEA_APPROVED;
            }


            if (!$user->isCommUser()) {
                if ($idea->isDirty('title')) {
                    $edited = true;
                }
                if ($idea->isDirty('details')) {
                    $edited = true;
                }
            }
            $idea['status_title'] = $idea->getStatusTitle();
            if ($request->filled('claimed_status') && $request->claimed_status) {
                $idea['claimed_status'] = $request->claimed_status;
            }
            if($request->status == Constants::IDEA_APPROVED && $request->filled('notes')){
                $idea['claimed_by'] = null;
                $idea['assigned_to'] = null;
                $idea['status'] = $request->status;
                $idea['notes'] = $request->notes;
                $idea['status_title'] = 'Approved';
                $idea['claimed_date'] = null;
                $idea['claimed_status'] = Constants::IDEA_PENDING;
            }
            $changes = $idea->getDirty();
            $idea->save();

            $this->sendIdeaMails($idea, $request->status, $request->claimed_status, $edited, $approved, $assignedTo, $claimedBy, $user,$original);
            $idea->sendNotification($request->status);

            if ($request->filled('assigned_journalist') && !empty($request->assigned_journalist)) {
                if ($request->filled('assigned_to') && !empty($request->assigned_to)) {
                    $data['notifiable_id'] = $idea->id;
                    $data['notifiable_type'] =  get_class($idea);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN,
                        Constants::TYPE_SUB_ADMIN
                    ];
                    $data['message'] = "Idea is assigned to journalist";
                    $data['type_id'] = Constants::NOTIFICATION_IDEA_ASSIGNED_TO_JOURNALIST;
                    $data['user_id'] = $request->assigned_to;
                    Helper::sendNotification($data);
                    $assignedUser = User::where('id', $request->assigned_to)->first();
                    $this->sendIdeaAssignedToJournalistMail($idea, $user->name, $user->slug, $assignedUser->name);
                }
            }
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
            if (in_array($user->role_id, $roleIdCheck)) {
                $data['notifiable_id'] = $idea->id;
                $data['notifiable_type'] =  get_class($idea);
                $data['created_by_id'] = $user->id;
                $data['state_id'] = 0;
                $data['roles'] = [
                    Constants::TYPE_SUPER_ADMIN,
                    Constants::TYPE_ADMIN,
                    Constants::TYPE_SUB_ADMIN
                ];
                $data['message'] = "Idea Updated by SuperAdmin|Admin|SubAdmin";
                $data['type_id'] = Constants::NOTIFICATION_IDEA_UPDATED_BY_ADMIN;
                $data['user_id'] = $request->assigned_to;
                Helper::sendNotification($data);
            }



                $revision = [
                'user_id' => $user->id,
                'revisionable_id' => $idea->id,
                'revisionable_type' => get_class($idea),
                'key' => null,
                'old_value' => null,
                'new_value' => null,
                'key_string' => $user->name . " updated the idea.",
                'type' => Constants::VERSION_IDEA
            ];
            Revision::create($revision);

            //Idea::versionHistory($idea, $original, $changes);
            $idea = $idea->fresh(['categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows', 'comments', 'assignedTo']);

            DB::commit();
            return $this->success(
                [
                    'data' => new IdeaResource($idea),
                    'message' => 'Idea updated successfully.'
                ]
            );
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }


    public function sendNotificationToContributor($data)
    {
      $notification = Notification::create([
                "message" => $data['message'],
                "is_read" => Constants::NOTIFICATION_NOT_READ,
                "state_id" => isset($data['state_id']) ? $data['state_id'] : 1,
                "user_id" => $data['user_id'],
                "type_id" => $data['type_id'],
                "notifiable_id" =>  $data['notifiable_id'],
                "notifiable_type" => $data['notifiable_type'],
                "model_id" =>  isset($data['model_id']) ? $data['model_id'] : null,
                "model_type" => isset($data['model_type']) ? $data['model_type'] : null,
                "created_by_id" =>  $data['created_by_id'],
                "is_alert" =>  isset($data['is_alert']) ? $data['is_alert'] : 0
            ]);
    }

    public function sendIdeaAssignedToJournalistMail($model, $name, $slug, $assinee)
    {
        $userslug = Constants::FRONTEND_URL . "profile/" . $slug;
        $subject = ' Idea Assignment to the Journalist ';
        $url =  Constants::FRONTEND_URL . "ideas/" . $model->slug;
        $first = " <a href='" . $userslug . "'>" . $name . '</a> has assigned to you a story idea about "' . $model->title . '". ';
        $this->customSendMail($model->assignedTo, (new IdeaMail($subject, ' Idea Assigned ', 'Hello', $first, '', $url, ' VIEW STORY IDEA ')));
        $admins = User::AdminData();
        if (!empty($admins)) {
            $adminfirst = ' ' . $assinee . ' has been assigned a story idea about ' . $model->title . ' ';
            foreach ($admins as $admin) {
                $this->customSendMail($admin, (new IdeaMail($subject, ' Idea Assigned ', 'Hello', $adminfirst, '', Constants::FRONTEND_URL . "/admin/idea/edit/" . $model->id, ' REVIEW IDEA ')));
            }
        }
        return true;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $idea = Idea::findOrFail($request->id);
        try {
            if ($idea->status != Constants::IDEA_CLAIMED) {
                $idea->delete();
                return $this->success('Idea deleted successfully');
            } else {
                return $this->failed('Idea cannot be deleted. Idea is at claimed state.');
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function yearMonthDropdown()
    {
        try {
        $idea = DB::table('ideas')
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')
        ->get();
        $callouts = DB::table('callouts')
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')
        ->get();
        $journalist = DB::table('users')->where('role_id', Constants::TYPE_JOURNALIST)
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')->get();
        $editor = DB::table('users')->where('role_id', Constants::TYPE_SUB_ADMIN)
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')->get();
        $mediahouse = DB::table('users')->where('role_id', Constants::TYPE_MEDIA_HOUSE)
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')->get();

        $communityUser = DB::table('users')->where('role_id', Constants::TYPE_COMMUNITY_USER)
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')->get();

        $media = DB::table('images')
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'DESC')
        ->orderBy('month', 'DESC')->get();

        $funding = DB::table('fundings')
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')->get();
        $pages = DB::table('pages')
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')->get();
        $invitations = DB::table('invites')
        ->select(DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'))
        ->distinct(DB::raw('YEAR(created_at), MONTH(created_at)'))
        ->orderBy('year', 'ASC')
        ->orderBy('month', 'ASC')->get();
        return $this->success(
            [
                'idea' => $idea,
                'callouts' => $callouts,
                'mediahouse' => $mediahouse,
                'editor' => $editor,
                'journalist' => $journalist,
                'community_user' => $communityUser,
                'media' => $media,
                'funding' => $funding,
                'pages' => $pages,
                'invitations' => $invitations
            ]
        );
     }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function zipcodeList()
    {
        $model = Idea::select('zipcode', DB::raw('COUNT(zipcode) AS count'))
        ->whereIn('status', [Constants::IDEA_APPROVED, Constants::IDEA_CLAIMED, Constants::IDEA_PUBLISHED])
        ->groupBy('zipcode')
        ->get();
        return $this->success(
            [
                'data' => IdeaZipcodeResource::collection($model)
            ]
        );
    }

    #phase 2 #18
    public function unclaimIdea(Request $request)
    {
        $request->validate([
            'notes' => 'required',
            'id' => 'required'
        ]);

        $idea = Idea::findOrFail($request->id);
        $original = $idea->getOriginal();
        $user = Auth::user();
        $unclaim = false;
        $claimedBy = !empty($idea->claimedBy) ? $idea->claimedBy : null;
        $assignedTo = !empty($idea->assignedTo) ? $idea->assignedTo : null;
        $collaborator = null;
        $assignedToColloborator = false;
        try {
            DB::beginTransaction();
            if ($idea->status == Constants::IDEA_CLAIMED) {
                if ($user->isAdmin() || $user->isSuperAdmin() || $user->isEditor()) {
                    // close all collaborator is there for this idea
                    $invite = Invite::where('idea_id', $idea->id)
                        ->where('state_id', Constants::INVITE_ACCEPTED)
                        ->update(['state_id' => Constants::INVITE_CLOSED]);
                    // close funding request by unclaim user.
                    Funding::where('fundable_id', $idea->id)
                    ->where('fundable_type', get_class($idea))
                    ->where('status', Constants::FUNDING_ACTIVE)
                    ->update(['status' => Constants::FUNDING_CLOSED]);
                    // close all group chat for this idea
                    $getGroup = Group::where('idea_id', $idea->id)
                    ->where('state_id', Constants::STATE_ACTIVE)->first();
                    $closeGroup = Group::where('idea_id', $idea->id)
                    ->where('state_id', Constants::STATE_ACTIVE)
                    ->update(['state_id' => Constants::STATE_DEACTIVATE]);
                    if($closeGroup){
                        GroupUser::where('group_id', $getGroup->id)
                            ->where('state_id', Constants::STATE_ACTIVE)
                            ->update(['state_id' => Constants::STATE_DEACTIVATE]);
                    }
                    $unclaim = true;
                }

                if ($user->isJournalist()) {
                    // if Journalist of idea
                    if ($idea->claimed_by == $user->id || $idea->assigned_to == $user->id) {
                        // check if any collaborator is there for this idea
                        $invite = Invite::where('idea_id', $idea->id)
                        ->where('state_id', Constants::INVITE_ACCEPTED)
                        ->first();
                        if (!empty($invite)) {
                            // carry forwarded by the Collaborator.
                            $idea['assigned_to'] = null;
                            $idea['claimed_by'] = $invite->user_id;
                            $assignedToColloborator = $invite;
                        } else {
                            $unclaim = true;
                        }
                    }
                    // if collaborator of idea unclaims
                    $collaborator = $idea->isJournalistInvited(true);
                    if (!empty($collaborator)) {
                        $invite = Invite::where('idea_id', $idea->id)
                        ->where('user_id', $user->id)
                        ->update(['state_id' => Constants::INVITE_CLOSED]);
                    }
                    // delete chatrooms as well.
                    $idea->groups()->each(function ($group) use ($user) {
                        // check if user exists in the chatroom and deactivate him from chatroom.
                        $groupUserExists = GroupUser::where('group_id', $group->id)
                        ->where('user_id', $user->id)
                        ->where('state_id', Constants::STATE_ACTIVE)
                        ->get();
                        // remove user from the idea groups if exists
                        if (!empty($groupUserExists) && $groupUserExists->count() > 0) {
                            foreach ($groupUserExists as $groupUserExist) {
                                $groupUserExist->update(['state_id' => Constants::STATE_DEACTIVATE]);
                            }
                        }
                        $sendMessageToGroupUsers = GroupUser::where('group_id', $group->id)->get();

                        // update all group members, of user leaving this group due to account close
                        if (!empty($sendMessageToGroupUsers) && $sendMessageToGroupUsers->count() > 0) {
                            $chatNew = Chat::create([
                                'message' => 'Left the chat group',
                                'group_id' => $group->id,
                                'type_id' => Constants::CHAT_TYPE_USER_LEFT,
                                'user_id' => $user->id
                            ]);
                            foreach ($sendMessageToGroupUsers as $groupUser) {
                                DB::table('chat_user')->insert(
                                    [
                                        'chat_id' => $chatNew->id,
                                        'user_id' => $groupUser->user_id,
                                        'is_read' => 0,
                                        'group_id' => $group->id,
                                        'created_at' => Carbon::now()->toDateTimeString()
                                    ]
                                );
                                broadcast(new GroupEvent($group->id, $groupUser->user_id));
                            }
                        }
                    });
                    // close funding request by unclaim user.
                    Funding::where('fundable_id', $idea->id)
                    ->where('fundable_type', get_class($idea))
                    ->where('user_id', $user->id)
                    ->update(['status' => Constants::FUNDING_CLOSED]);
                }

                if ($unclaim) {
                    $idea['status'] = Constants::IDEA_APPROVED;
                    $idea['claimed_notification_sent'] = 0;
                    $idea['claimed_mail_sent'] = 0;
                    $idea['status_title'] = 'Approved';
                    $idea['claimed_date'] = NULL;
                    $idea['claimed_status'] = 0;
                    $idea['claimed_by'] = null;
                    $idea['assigned_to'] = null;
                }
                $idea['notes'] = $request->notes;
                $idea->save();
                $this->sendUnclaimNotification($idea, $claimedBy, $assignedTo, $assignedToColloborator, $collaborator);
                DB::commit();
                return $this->success('Idea unclaimed successfully');
            } else {
                return $this->failed('This idea cannot be unclaimed.');
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function sendUnclaimNotification($idea, $claimedBy, $assignedTo, $assignedToColloborator, $collaborator, $closedAccount = false)
    {
        $user = Auth::user();
        if ($user) {
            $notes = " Idea Unclaim Notes: " . $idea->notes . " ";
            if ($closedAccount) {
                $first = " " . $user->name . ' has unclaimed story idea: "' . $idea->title . '"  due to closing of the account. ';
            } else {
                $first = " " . $user->name . ' has unclaimed story idea: "' . $idea->title . '" ';
            }

            $data = [];
            $data['notifiable_id'] = $idea->id;
            $data['notifiable_type'] = get_class($idea);
            $data['created_by_id'] = $user->id;
            $data['is_alert'] = true;
            $data['message'] = $first;
            $data['type_id'] = Constants::NOTIFICATION_IDEA_UNCLAIMED;
            $subject = " Idea : UnClaimed ";

            if ($user->isJournalist()) {
                if ((!empty($claimedBy) && ($claimedBy->id == $user->id))
                    ||
                    (!empty($assigned_to) && ($assignedTo->id == $user->id))
                ) {
                    // If a Journalist unclaims an idea the notification would go to:
                    $subject = " Idea : UnClaimed by journalist ";
                $btnText = ' VIEW STORY IDEA ';

                    //Contributor
                if (!empty($idea->createdBy)) {
                    $url = Constants::FRONTEND_URL . "/contributor/idea/edit/" . $idea->id;
                    $this->customSendMail($idea->createdBy, (new IdeaMail(
                        $subject,
                        $first,
                        'Hello',
                        $first,
                        $notes,
                        $url,
                        $btnText
                    )));
                    $data['user_id'][] = $idea->created_by;
                }

                    //Media House Admin
                $mediaHouse = $user->myMediaHouse(true);
                if (!empty($mediaHouse)) {
                    $url = Constants::FRONTEND_URL . "/mediahouse/idea/edit/" . $idea->id;
                    $this->customSendMail($mediaHouse, (new IdeaMail(
                        $subject,
                        $first,
                        'Hello',
                        $first,
                        $notes,
                        $url,
                        $btnText
                    )));
                    $data['user_id'][] = $mediaHouse->id;
                }

                    //Collaborator
                if (!empty($this->invites)) {
                    $url = Constants::FRONTEND_URL . "/journalist/idea/edit/" . $idea->id;
                    foreach ($this->invites as $invite) {
                        if (!empty($invite) && !empty($invite->user)) {
                            $this->customSendMail($invite->user, (new IdeaMail(
                                $subject,
                                $first,
                                'Hello',
                                $first,
                                $notes,
                                $url,
                                $btnText
                            )));
                            $data['user_id'][] = $invite->user->id;
                        }
                    }
                }

                    //Admin
                $admins = User::AdminData();
                if (!empty($admins)) {
                    $url = Constants::FRONTEND_URL . "/admin/idea/edit/" . $idea->id;
                    foreach ($admins as $admin) {
                        if (!empty($admin)) {
                            $this->customSendMail($admin, (new IdeaMail(
                                $subject,
                                $first,
                                'Hello',
                                $first,
                                $notes,
                                $url,
                                $btnText
                            )));
                            $data['user_id'][] = $admin->id;
                        }
                    }
                }

                    //Followers of the idea
                if (!empty($idea->follows)) {
                    $url = Constants::FRONTEND_URL . '/ideas/' . $idea->slug;
                    foreach ($idea->follows as $follow) {
                        if (!empty($follow) && !empty($follow->user)) {
                            $this->customSendMail($follow->user, (new IdeaMail(
                                $subject,
                                $first,
                                'Hello',
                                $first,
                                $notes,
                                $url,
                                $btnText
                            )));
                            $data['user_id'][] = $follow->user->id;
                        }
                    }
                }
            }

            if (!empty($collaborator)) {
                    // to invited journalist
                if ($closedAccount) {
                    $invitedfirst = " Your invited journalist, " . $user->name . ' has unclaimed story idea: "' . $idea->title . '"  due to closing of the account. ';
                    $toCollab = [];
                    $toCollab['notifiable_id'] = $user->id;
                    $toCollab['notifiable_type'] = get_class($user);
                    $toCollab['created_by_id'] = $user->id;
                    $toCollab['is_alert'] = true;
                    $toCollab['message'] = $first;
                    $toCollab['type_id'] = Constants::NOTIFICATION_USER_ACCOUNT_CLOSE;
                    $toCollab['user_id'] = $collaborator->createdBy->id;
                    Helper::sendNotification($toCollab);
                } else {
                    $invitedfirst = " Your invited journalist, " . $user->name . " has unclaimed story idea: " . $idea->title . " ";
                }
                $url = Constants::FRONTEND_URL . "/ideas/" . $idea->slug;
                $subjectInvited = " Idea Unclaimed: " . $idea->title . " ";
                $btnInvited = " VIEW IDEA ";
                $this->customSendMail($collaborator->createdBy, (new IdeaMail(
                    $subjectInvited,
                    $invitedfirst,
                    'Hello',
                    $invitedfirst,
                    $notes,
                    $url,
                    $btnInvited
                )));


                    // If a Collaborator unclaims an idea the notification would go to:
                $subject = " Idea : UnClaimed by Collaborator ";
                $btnText = ' VIEW STORY IDEA ';

                    // Journalist
                if (!empty($claimedBy)) {
                    $url = Constants::FRONTEND_URL . "/ideas/" . $idea->slug;
                    $this->customSendMail($claimedBy, (new IdeaMail(
                        $subject,
                        $first,
                        'Hello',
                        $first,
                        $notes,
                        $url,
                        $btnText
                    )));
                    $data['user_id'][] = $claimedBy->id;
                }

                    // assigned to
                if (!empty($assignedTo)) {
                    $url = Constants::FRONTEND_URL . "/ideas/" . $idea->slug;
                    $this->customSendMail($assignedTo, (new IdeaMail(
                        $subject,
                        $first,
                        'Hello',
                        $first,
                        $notes,
                        $url,
                        $btnText
                    )));
                    $data['user_id'][] = $assignedTo->id;
                }

                    //Contributor
                if (!empty($idea->createdBy)) {
                    $url = Constants::FRONTEND_URL . "/contributor/idea/edit/" . $idea->id;
                    $this->customSendMail($idea->createdBy, (new IdeaMail(
                        $subject,
                        $first,
                        'Hello',
                        $first,
                        $notes,
                        $url,
                        $btnText
                    )));
                    $data['user_id'][] = $idea->created_by;
                }
                    //Collaborator
                if (!empty($idea->invites)) {
                    $url = Constants::FRONTEND_URL . "/ideas/" . $idea->slug;
                    foreach ($idea->invites as $invite) {
                        if (!empty($invite) && !empty($invite->user)) {
                            $this->customSendMail($invite->user, (new IdeaMail(
                                $subject,
                                $first,
                                'Hello',
                                $first,
                                $notes,
                                $url,
                                $btnText
                            )));
                            $data['user_id'][] = $invite->user->id;
                        }
                    }
                }
                    //Admin
                $admins = User::AdminData();
                if (!empty($admins)) {
                    $url = Constants::FRONTEND_URL . "/admin/idea/edit/" . $idea->id;
                    foreach ($admins as $admin) {
                        if (!empty($admin)) {
                            $this->customSendMail($admin, (new IdeaMail(
                                $subject,
                                $first,
                                'Hello',
                                $first,
                                $notes,
                                $url,
                                $btnText
                            )));
                            $data['user_id'][] = $admin->id;
                        }
                    }
                }
                    //     - Followers of the idea
                if (!empty($idea->follows)) {
                    $url = Constants::FRONTEND_URL . "/ideas/" . $idea->slug;
                    foreach ($idea->follows as $follow) {
                        if (!empty($follow) && !empty($follow->user)) {
                            $this->customSendMail($follow->user, (new IdeaMail(
                                $subject,
                                $first,
                                'Hello',
                                $first,
                                $notes,
                                $url,
                                $btnText
                            )));
                            $data['user_id'][] = $follow->user->id;
                        }
                    }
                }
            }

                //to journalist itself
            $subject = " Idea unclaimed ";
            $first = " Idea " . $idea->title . " is unclaimed successfully. ";
            $url = Constants::FRONTEND_URL . "/contributor/idea/edit/" . $idea->id;
            $this->customSendMail($user, (new IdeaMail(
                $subject,
                $first,
                'Hello',
                " You have successfully unclaimed the idea: " . $idea->title . " ",
                "",
                "",
                ""
            )));
            $data['user_id'][] = $user->id;
        }
        if ($user->isAdmin()) {
            $subject = " Idea : UnClaimed by Admin ";
            $btnText = ' VIEW STORY IDEA ';
                // If Admin unclaims an idea from a Journalist, the notifications would go to:
                //Journalist
            if (!empty($claimedBy)) {
                $url = Constants::FRONTEND_URL . "/ideas" . $idea->slug;
                $this->customSendMail($claimedBy, (new IdeaMail(
                    $subject,
                    $first,
                    'Hello',
                    $first,
                    $notes,
                    $url,
                    $btnText
                )));
                $data['user_id'][] = $claimedBy->id;
            }

                // assigned to
            if (!empty($assignedTo)) {
                $url = Constants::FRONTEND_URL . "/ideas" . $idea->slug;
                $this->customSendMail($assignedTo, (new IdeaMail(
                    $subject,
                    $first,
                    'Hello',
                    $first,
                    $notes,
                    $url,
                    $btnText
                )));
                $data['user_id'][] = $assignedTo->id;
            }

                //Contributor
            if (!empty($idea->createdBy)) {
                $url = Constants::FRONTEND_URL . "/contributor/idea/edit/" . $idea->id;
                $this->customSendMail($idea->createdBy, (new IdeaMail(
                    $subject,
                    $first,
                    'Hello',
                    $first,
                    $notes,
                    $url,
                    $btnText
                )));
                $data['user_id'][] = $idea->created_by;
            }

                //Collaborator
            if (!empty($idea->invites)) {
                $url = Constants::FRONTEND_URL . "/ideas/" . $idea->slug;
                foreach ($idea->invites as $invite) {
                    if (!empty($invite) && !empty($invite->user)) {
                        $this->customSendMail($invite->user, (new IdeaMail(
                            $subject,
                            $first,
                            'Hello',
                            $first,
                            $notes,
                            $url,
                            $btnText
                        )));

                        $data['user_id'][] = $invite->user->id;
                    }
                }
            }
                //     - Media House Admin
                //     - Followers of the idea
            if (!empty($idea->follows)) {
                $url = Constants::FRONTEND_URL . "/ideas/" . $idea->slug;
                foreach ($idea->follows as $follow) {
                    if (!empty($follow) && !empty($follow->user)) {
                        $this->customSendMail($follow->user, (new IdeaMail(
                            $subject,
                            $first,
                            'Hello',
                            $first,
                            $notes,
                            $url,
                            $btnText
                        )));
                        $data['user_id'][] = $follow->user->id;
                    }
                }
            }
        }
        $revision = [
            'user_id' => $user->id,
            'revisionable_id' => $idea->id,
            'revisionable_type' => get_class($idea),
                'key_string' => $user->name . " UnClaimed the idea.", // journalist 1 and contributor 2
                'type' => Constants::VERSION_IDEA
            ];
            Revision::create($revision);
            if (!empty($assignedToColloborator)) {
                $subject = " Idea : UnClaimed by journalist and carry forwarded to your claimed Idea list. ";
                $first = " " . $user->name . ' has unclaimed story idea: "' . $idea->title . '" ';
                $btnText = ' VIEW STORY IDEA ';

                //collaborator
                if (!empty($assignedToColloborator->user)) {
                    $url = Constants::FRONTEND_URL . "/journalist/idea/edit/" . $idea->id;
                    $this->customSendMail($assignedToColloborator->user, (new IdeaMail(
                        $subject,
                        $first,
                        'Hello',
                        $first,
                        $notes,
                        $url,
                        $btnText
                    )));

                    $data['user_id'][] = $assignedToColloborator->user->id;

                    //Admin
                    $admins = User::AdminData();
                    if (!empty($admins)) {
                        $url = Constants::FRONTEND_URL . "/admin/idea/edit/" . $idea->id;
                        $subject = " Idea : UnClaimed by journalist and carry forwarded. ";
                        $first = " " . $user->name . ' has unclaimed story idea: "' . $idea->title . '" and carry forwarded to collaborator.';

                        foreach ($admins as $admin) {
                            if (!empty($admin)) {
                                $this->customSendMail($admin, (new IdeaMail(
                                    $subject,
                                    $first,
                                    'Hello',
                                    $first,
                                    $notes,
                                    $url,
                                    $btnText
                                )));
                                $data['user_id'][] = $admin->id;
                            }
                        }
                    }

                    $revision = [
                        'user_id' => $user->id,
                        'revisionable_id' => $idea->id,
                        'revisionable_type' => get_class($idea),
                        'key_string' => " This idea is carry forwarded to " . $assignedToColloborator->user->name,
                        'type' => Constants::VERSION_IDEA
                    ];
                    Revision::create($revision);
                }
            }

            Helper::sendNotification($data);
        }
        return true;
    }
}
