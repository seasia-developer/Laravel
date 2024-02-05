<?php

namespace App\Http\Controllers;

use App\Constant\Constants;
use App\Funding;
use App\Http\Helper\Helper;
use App\Http\Resources\FundingResource;
use App\Idea;
use App\Notifications\IdeaMail;
use App\Revision;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FundingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $user = Auth::guard('api')->user();

        $list = Funding::latest('id');
        $count = $list->count();

        if ($user) {
            if (!$request->frontend) {
                if ($user->isJournalist()) {
                    $list = $list->where('user_id', $user->id);
                    $count = $list->count();
                }
                if ($user->isMediaHouse()) {
                    $journalists = $user->mediaHouseJournalists();
                    array_push($journalists, $user->id);
                    $list = $list->whereIn('user_id', $journalists);
                    $count = $list->count();
                }
            } else {
                $list = $list->where('status', Constants::FUNDING_ACTIVE);
            }
        } else {
            $list = $list->where('status', Constants::FUNDING_ACTIVE);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $list = $list->where(function ($query) use ($search) {
                $query->whereHasMorph(
                    'fundable',
                    ['App\Idea'],
                    function ($q) use ($search) {
                        $q->where('title', 'LIKE', '%' . $search . '%');
                    }
                );
            });
            $list = $list->orwhere(function ($query) use ($search) {
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'LIKE', '%' . $search . '%');
                    $q->orwhere(function ($m) use ($search) {
                        $m->whereHas('mediahouse', function ($p) use ($search) {
                            $p->where('name', 'LIKE', '%' . $search . '%');
                        });
                    });
                });
            });
        }
        if ($request->filled('status')) {
            $list = $list->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $list = $list->where('user_id', $request->created_by);
        }
        if ($request->filled('month') && $request->filled('year')) {
            $month = Carbon::createFromDate($request->year, $request->month);
            $start = $month->startOfMonth()->toDateTimeString();
            $end = $month->endOfMonth()->toDateTimeString();
            $list = $list->whereBetween('created_at', [
                $start,
                $end
            ]);
        }

        $list = $list->paginate($limit);
        return $this->success([
            'allFundings' => $count,
            'data' => FundingResource::collection($list),
            'message' => 'Fundings List fetched successfully',
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'amount' => "required|regex:/^\d+(\.\d{1,2})?$/",
            'link' => 'string',
            'notes' => 'string',
            'closing_at' => 'nullable|date',
            'fundable_id' => 'required|integer'
        ]);
        $idea = Idea::findOrFail($request->fundable_id);
        $auth = Auth::user();
        $mediahouseSendMail = null;
        $sendToUsers = [];
        try {
            $funding = new Funding();
            $funding->amount = $request->amount;
            $funding->link = $request->link;
            $funding->notes = $request->notes;
            $funding->closing_at = $request->closing_at;
            $funding->collected_amount = $request->collected_amount;
            $funding->status = Constants::STATE_ACTIVE;
            $funding->user()->associate($request->user());
            $idea->fundings()->save($funding);
            // store revision as funding initiated
            $revision = [
                'user_id' => Auth::user()->id,
                'revisionable_id' => $idea->id,
                'revisionable_type' => get_class($idea),
                'key_string' => $auth->name . " initiated the funding request.",
                'type' => Constants::VERSION_IDEA
            ];
            Revision::create($revision);
            //revision created\
            $data = [];
            $data['message'] = "Added new Funding on Idea notification to admin";
            $data['state_id'] = 0;
            $data['type_id'] = Constants::NOTIFICATION_FUNDING_NEW;
            $data['notifiable_id'] = $idea->id;
            $data['notifiable_type'] = get_class($idea);
            $data['model_id'] = $funding->id;
            $data['model_type'] = get_class($funding);
            $data['created_by_id'] = $funding->user_id;
            $data['roles'] = [
                Constants::TYPE_SUPER_ADMIN,
                Constants::TYPE_ADMIN,
                Constants::TYPE_SUB_ADMIN,
            ];

            // [JOURNALIST’S NAME/NEWS ORG] has submitted a request for funding for the idea [topic name].
            $creatorName = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $auth->slug . '">' . $auth->name . '</a> ';
            if ($auth->isJournalist()) {
                if (!empty($auth->mediaHouse)) {
                    $mediahouseSendMail = $auth->mediaHouse;
                    $sendToUsers[] = $auth->mediaHouse->id;
                    $creatorName .= ' / <a href="' . Constants::FRONTEND_URL . "profile/" .  $auth->mediaHouse->slug . '">' .
                        $auth->mediaHouse->name . '</a> ';
                }
            }
            $sendToUsers[] = $idea->created_by;
            if (!empty($idea->follows)) {
                foreach ($idea->follows as $follow) {
                    if (!empty($follow) && !empty($follow->user)) {
                        $sendToUsers[] = $follow->user->id;
                    }
                }
            }
            $data['user_id'] = $sendToUsers;
            Helper::sendNotification($data);
         
            $this->sendFundMail($idea, Constants::NOTIFICATION_FUND_REQUEST_SUBMISSION, $creatorName, $mediahouseSendMail, $auth);
            return $this->success([
                'data' => new FundingResource($funding),
                'message' => 'Funding stored successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function sendFundMail($model, $status, $creatorname = '', $mediahouseSendMail = null, $user)
    {
         
        $url = Constants::FRONTEND_URL . "ideas/" . $model->slug;
        $admins = User::whereIn('role_id', [Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN])->where('state_id', Constants::STATE_ACTIVE)->get();

        $created_by = User::where('id', $model->created_by)->first();
        // print '<pre>';
        // print_r($created_by);
        // exit;

        $heading = ' Funding : "' . $model->title . '" ';
        $subject = " Fund Request Submission ";
        $first = " " . $creatorname . ' has submitted a request for funding for the idea "' . $model->title . '". ';
        $btnText = " VIEW REQUEST ";
        switch ($status) {
            case Constants::NOTIFICATION_FUND_REQUEST_SUBMISSION:

                if (!empty($admins)) {
                    foreach ($admins as $admin) {
                        $this->customSendMail($admin, (new IdeaMail($subject, $heading, 'Hello', $first, '', $url, $btnText)));
                    }
                }

                if(!empty($created_by))
                {
                    $this->customSendMail($created_by, (new IdeaMail($subject, $heading, 'Hello', $first, '', $url, $btnText))); 
                }

               
                if (!empty($mediahouseSendMail)) {
                    // $mediaUrl =  Constants::FRONTEND_URL . "ideas/" . $model->slug;
                    $this->customSendMail($mediahouseSendMail, (new IdeaMail($subject, $heading, 'Hello', $first, '', $url, $btnText)));
                }

                // followers
                if (!empty($model->follows)) {
                    $url = Constants::FRONTEND_URL . "/ideas/" . $model->slug;
                    $subject = " Funding Initiated ";
                    $btnText = " VIEW IDEA ";
                    $notes = "";
                    $first = " " . $user->name . " has Initiated funding on this Idea: '" . $model->title . "', you are recieving mail as you are following this Idea.  ";
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
                break;
            default:
                break;
        }
        return true;
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {

        $funding = Funding::findOrFail($id);
        return $this->success([
            'data' => new FundingResource($funding),
            'message' => 'Funding fetched successfully.'
        ]);
      }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $request->validate([
            'amount' => "regex:/^\d+(\.\d{1,2})?$/",
            'collected_amount' => "regex:/^\d+(\.\d{1,2})?$/",
            'link' => 'string',
            'notes' => 'string',
            'closing_at' => 'nullable|date',
            'fundable_id' => 'required|integer'
        ]);
        $idea = Idea::findOrFail($request->fundable_id);
        $funding = Funding::findOrFail($request->id);
        try {
            $funding->update($request->all());
            // store revision as funding initiated
            $revision = [
                'user_id' => Auth::user()->id,
                'revisionable_id' => $idea->id,
                'revisionable_type' => get_class($idea),
                'key_string' => Auth::user()->name . " updated the funding request.",
                'type' => Constants::VERSION_IDEA
            ];
            Revision::create($revision);
            //revision created
            return $this->success([
                'data' => new FundingResource($funding),
                'message' => 'Funding updated successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $funding = Funding::findOrFail($request->id);
        $idea = Idea::findOrFail($funding->fundable_id);
        try {
            $funding->delete();
            // store revision as funding initiated
            $revision = [
                'user_id' => Auth::user()->id,
                'revisionable_id' => $idea->id,
                'revisionable_type' => get_class($idea),
                'key_string' => Auth::user()->name . " deleted the funding request.",
                'type' => Constants::VERSION_IDEA
            ];
            Revision::create($revision);
            //revision created
            return $this->success('Funding deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
