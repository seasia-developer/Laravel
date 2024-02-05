<?php

namespace App\Http\Controllers;

use App\Callout;
use App\Constant\Constants;
use App\Http\Helper\Helper;
use App\Http\Resources\CalloutResource;
use App\Image;
use App\Notifications\CalloutMail;
use App\Rules\MaxTags;
use App\Tag;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CalloutController extends Controller
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
        $list = Callout::query();
        if ($user) {
            if (!$request->for_home) {
                if ($user->isJournalist()) {
                    $list->where('created_by', $user->id);
                }
                if ($user->isMediaHouse()) {
                    $company = $user->mediaHouseJournalists(); // get MH -> journalists
                    array_push($company, $user->id); // merge MH + Journalists
                    $list->whereIn('created_by', $company); // get MH + Journalists Ideas based on above status
                    // if ($request->filled('for_home')) {

                    // } else {
                    //     $list->where('created_by', $user->id);
                    // }
                }
            }
        }
        if($request->for_home){
            $list = $list
            ->orderByRaw(
                "CASE
                WHEN status = 1 OR is_featured = 1
                THEN (
                CASE
                WHEN status = 1 AND created_at >= updated_at
                THEN  created_at
                WHEN is_featured = 1 AND featured_date >= updated_at
                THEN featured_date
                END
                )
                END
                DESC"
            );
            $list->where('status','!=',Constants::STATE_DEACTIVATE);
        }else{
            $list = $list->latest('id');
        }
        $count = $list->count();
        if ($request->filled('search')) {
            $list->where('title', 'like', "%{$request->search}%");
        }
        if ($request->filled('status')) {
            $list->where('status', $request->status);
        }
        if ($request->filled('created_by')) {
            $list->where('created_by', $request->created_by);
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
        $list = $list->paginate($limit);
        return $this->success([
            'allCallout' => $count,
            'data' => CalloutResource::collection($list),
            'message' => 'Callout List fetched successfully',
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
            // 'contact_email' => 'required',
            // 'contact_number' => 'required',
            'categories' => 'required',
            'status' => 'required'
        ]);
        $user = Auth::guard('api')->user();
        try {
            if ($user) {
                $request['created_by'] = $user->id;
            } else {
                $request->validate([
                    'created_by' => 'required'
                ]);
            }
            $request['slug'] = $this->createSlug(Callout::class, $request->title);

            $model = Callout::create($request->all());
            if (!empty($request->categories)) {
                $categoriesArr = explode(",", $request->categories);
                $categories = array_filter($categoriesArr);
                $model->calloutCategories()->attach($categories);
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
                $model->tags()->attach($tagId);
            }

            if ($request->hasFile('featured_image')) {
                $uploadedImage = Helper::saveImage($request->featured_image, 'callout-images', $model->id);
                $image = new Image();
                $image->name = $request->featured_image->getClientOriginalName();
                $image->featured = 1;
                $image->file_name =  $uploadedImage;
                $image->extension = $request->featured_image->getClientOriginalExtension();
                if ($user) {
                    $image->user()->associate($user);
                }
                $model->images()->save($image);
            }

            $data = [
                'message' => "Added new callout notification to admin",
                'state_id' => 0,
                'type_id' => Constants::NOTIFICATION_CALLOUT_NEW,
                'notifiable_id' => $model->id,
                'notifiable_type' => get_class($model),
                'created_by_id' => $model->created_by,
                'roles' => [
                    Constants::TYPE_SUPER_ADMIN,
                    Constants::TYPE_ADMIN,
                    Constants::TYPE_SUB_ADMIN
                ]
            ];
            Helper::sendNotification($data);
            $this->sendNotifyMail($model, Constants::NOTIFICATION_NEW_CALLOUT_SUBMIT_NOTIFY_ADMIN);
            $mediahouse = $user->myMediaHouse(true);
            if (!empty($mediahouse)) {
                $data = [
                    'message' => "Added new callout notification to media house",
                    'state_id' => 0,
                    'type_id' => Constants::NOTIFICATION_CALLOUT_NEW,
                    'notifiable_id' => $model->id,
                    'notifiable_type' => get_class($model),
                    'created_by_id' => $model->created_by,
                    'user_id' => $mediahouse->id
                ];
                Helper::sendNotification($data);

                $media_subject = " Callout Submission ";
                $media_first = "  " . $user->name . " have submitted a callout. ";
                $media_url =  Constants::FRONTEND_URL . "mediahouse/callout/edit/" . $model->id;
                $this->customSendMail($mediahouse, (new CalloutMail($media_subject, $media_subject, 'Hello', $media_first, '', $media_url, " VIEW CALLOUT ")));
            }
            return $this->success([
                'data' => new CalloutResource($model),
                'message' => 'Callout stored successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function sendNotifyMail($model, $status)
    {
        $url =  Constants::FRONTEND_URL . "callouts/details/" . $model->slug;
        // to admin also
        $admin_subject = " Callout Submission ";
        $admins = User::whereIn('role_id', [Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN])->where('state_id', Constants::STATE_ACTIVE)->get();
        if (!empty($admins)) {
            if ($status == Constants::NOTIFICATION_NEW_CALLOUT_SUBMIT_NOTIFY_ADMIN) {
                $admin_subject = " Callout Submission ";
                $admin_first = ' A response to the callout about "' . $model->title . '" has been submitted. ';
                $url =  Constants::FRONTEND_URL . 'admin/callout/edit/' . $model->id;
                $admin_btnText = ' VIEW RESPONSE ';
                foreach ($admins as $admin) {
                    $this->customSendMail($admin, (new CalloutMail($admin_subject, $admin_subject, 'Hello', $admin_first, '', $url, $admin_btnText)));
                }
            }
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
        $model = Callout::findOrFail($id);
        if (!$model->checkWhoCanViewUpdate()) {
            return $this->failed("You have no access to this Callout.");
        } else {
            return $this->success([
                'data' => new CalloutResource($model),
                'message' => 'Callout Fetch Successfully.'
            ]);
        }
    }

    public function showBySlug($slug)
    {
        $model = Callout::where('slug', $slug)->first();
        if (!empty($model)) {
            return $this->success([
                'data' => new CalloutResource($model),
                'message' => 'Callout Fetch Successfully.'
            ]);
        }
        return $this->failed('Callout Not Found');
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
        $user = Auth::user()->id;
        $request->validate([
            // 'contact_number' => 'required',
            // 'contact_email' => 'required',
            'categories' => 'required',
            'id' => 'required'
        ]);
        $model = Callout::findOrFail($request->id);
        if ($model->checkWhoCanViewUpdate()) {
            try {
                if (!empty($request->categories)) {
                    $catArr = explode(',', $request->categories);
                    $model->calloutCategories()->sync($catArr);
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
                    $model->tags()->sync($tagId);
                }else
                {
                    $calloutId = $request->id;
                    DB::delete("delete from callout_tag where callout_id= '$calloutId'");
                }
                $featuredImage = Image::where('imageable_id', $request->id)->where('imageable_type', Callout::class)->first();

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
                    $uploadedImage = Helper::saveImage($request->featured_image, 'callout-images', $model->id);
                    if (!empty($featuredImage)) {
                        Helper::removeImage($featuredImage->file_name);
                        $featuredImage->update([
                            'name' => $request->featured_image->getClientOriginalName(),
                            'file_name' => $uploadedImage,
                            'extension' => $request->featured_image->getClientOriginalExtension()
                        ]);
                    } else {
                        $image = new Image();
                        $image->name = $request->featured_image;
                        $image->featured = 1;
                        $image->file_name =  $uploadedImage;
                        if ($user) {
                            $image->user()->associate($user);
                        }
                        $model->images()->save($image);
                    }
                }
                if ($request->title != $model->getOriginal('title')) {
                    $request['slug'] = $this->createSlug(Callout::class, $request->title, $model->id);
                }
                $model->update($request->all());
                $data = [
                'message' => "Updated callout notification to admin",
                'state_id' => 0,
                'type_id' => Constants::NOTIFICATION_CALLOUT_UPDATED_BY_ADMIN,
                'notifiable_id' => $model->id,
                'notifiable_type' => get_class($model),
                'created_by_id' => $model->created_by,
                'roles' => [
                    Constants::TYPE_SUPER_ADMIN,
                    Constants::TYPE_ADMIN,
                    Constants::TYPE_SUB_ADMIN
                ]
            ];
            Helper::sendNotification($data);
                return $this->success(
                    [
                        'data' => new CalloutResource($model),
                        'message' => 'Callout updated successfully.'
                    ]
                );
            } catch (\Illuminate\Database\QueryException $exception) {
                return $this->error($exception->errorInfo);
            }
        } else {
            return $this->failed("You have no access to this Callout.");
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function calloutFeature(Request $request)
    {
        try {
            $callout = Callout::findOrFail($request->id);
            if($request->is_featured == Constants::STATE_ACTIVE){
                $callout->update([
                    'is_featured' => $request->is_featured,
                    'featured_date'=> Carbon::now()
                ]);
            }else{
                $callout->update([
                    'is_featured' => $request->is_featured
                ]);
            }
            return $this->success("Marked to Featured Successfully");
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
        $model = Callout::findOrFail($request->id);
        $modeldata = Callout::findOrFail($request->id);
        try {
            $model->delete();
            $data = [
                'message' => "Deleted callout notification to admin",
                'state_id' => 0,
                'type_id' => Constants::NOTIFICATION_CALLOUT_DELETED_BY_ADMIN,
                'notifiable_id' => $modeldata->id,
                'notifiable_type' => get_class($modeldata),
                'created_by_id' => $modeldata->created_by,
                'roles' => [
                    Constants::TYPE_SUPER_ADMIN,
                    Constants::TYPE_ADMIN,
                    Constants::TYPE_SUB_ADMIN
                ]
            ];
            Helper::sendNotification($data);
            return $this->success('Callout deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
