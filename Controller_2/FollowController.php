<?php

namespace App\Http\Controllers;

use App\Constant\Constants;
use App\Follow;
use App\Http\Helper\Helper;
use App\Http\Resources\IdeaResource;
use App\Idea;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FollowController extends Controller
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
        $user = Auth::user();
        $followingIdeas = $user->follows->pluck('followable_id')->toArray();

        if ($user->isMediaHouse()) {
            $mediaJournalists = $user->mediaHouseJournalists();
            if ($mediaJournalists) {
                // $journalistsFollowedIdeas = Follow::whereIn('user_id', $mediaJournalists)->pluck('followable_id')->toArray();
                $journalistsFollowedIdeas = Follow::whereIn('user_id', $mediaJournalists)->orderBy('created_at', 'ASC')->pluck('followable_id')->toArray();
                if ($journalistsFollowedIdeas) {
                    $followingIdeas = array_merge($followingIdeas, $journalistsFollowedIdeas);
                }
            }
        }
        $list = Idea::whereIn('ideas.id', $followingIdeas)
            ->with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows', 'assignedTo');
        $count = $list->count();

        if ($request->filled('search')) {
            $list->where('title', 'like', "%{$request->search}%")
                ->orWhere('details', 'like', "%{$request->search}%");
        }
        if ($request->filled('status')) {
            $list->where('status', $request->status);
        }
        if ($request->filled('month')) {
            $month = Carbon::createFromFormat('m', $request->month);
            $start = $month->startOfMonth()->toDateTimeString();
            $end = $month->endOfMonth()->toDateTimeString();
            $list->whereBetween('created_at', [
                $start,
                $end
            ]);
        }

        if ($request->filled('for_home')) {
            // $list = $list
            //     ->orderByRaw(
            //         "CASE
            //             WHEN status = 2 OR status = 1 OR status = 5
            //                 THEN (
            //                     CASE
            //                         WHEN status = 2 AND published_date >= created_at AND published_date >= approved_date AND published_date >= claimed_date
            //                             THEN  published_date
            //                             WHEN status = 1 AND approved_date >= created_at AND approved_date >= claimed_date
            //                                 THEN  approved_date
            //                             WHEN status = 5 AND claimed_date >= created_at
            //                                 THEN  claimed_date
            //                             ELSE updated_at
            //                     END
            //                     )
            //         END
            //         DESC"
            //     );
            if (!empty($followingIdeas)) {
                $list = $list->orderByRaw("FIELD(id ," . implode(',', $followingIdeas) . " ) ASC");
            }
            $list = $list->take($limit)->get();
        } else {
            if ($request->filled('sortField') && $request->filled('sortOrder')) {
                if ($request->sortField == 'admin_status') {
                    $list = $list->orderBy('status_title', $request->sortOrder);
                }elseif ($request->sortField == 'created_by.name' || $request->sortField == 'contributor') {
                    $list = $list->join('users', 'ideas.created_by', '=', 'users.id')
                        ->orderBy('users.name', $request->sortOrder);
                } elseif ($request->sortField == 'claimed_by.name') {
                        $list = $list->join('users', 'ideas.claimed_by', '=', 'users.id')
                        ->orderBy('users.name', $request->sortOrder);
                }else {
                    $list = $list->orderBy($request->sortField, $request->sortOrder);
                }
            }else {
                $list = $list->latest('id');
            }
            $list = $list->paginate($limit);
        }
        return $this->success([
            'allIdeas' => $count,
            'data' => IdeaResource::collection($list),
            'message' => 'Idea List fetched successfully',
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
    public function followIdea(Request $request)
    {
        $request->validate([
            "follow_id" => "required"
        ]);
        $idea = Idea::findorFail($request->follow_id);
        $user = Auth::user();
        $exists = Follow::where('user_id', $user->id)
            ->where('followable_id', $request->follow_id)
            ->first();
        if ($exists) {
            if ($exists->state_id == Constants::STATE_DEACTIVATE) {
                $exists->update([
                    'state_id' => Constants::STATE_ACTIVE
                ]);
                return $this->success([
                    "data" => new IdeaResource($idea),
                    "message" => 'Followed Successfully.'
                ]);
            } else {
                return $this->error('Already following');
            }
        }
        try {
            $follow = new Follow();
            $follow->state_id = Constants::STATE_ACTIVE;
            $follow->user()->associate($user);
            $idea->follows()->save($follow);
            $sendToUsers = [];
            $sendToUsers[] = $idea->created_by;
            if (!empty($idea->claimed_by)) {
                if ($user->id != $idea->claimed_by) {
                    $sendToUsers[] = $idea->claimed_by;
                }
                $myMedia = $user->myMediaHouse();
                if (isset($myMedia[0]) && !empty($myMedia[0])) {
                    $sendToUsers[] = $myMedia[0];
                }
            }

            $data = [
                'message' => "followed this Idea sending to admin|subadmin|comm. user|journalist",
                'state_id' => 0,
                'type_id' => Constants::NOTIFICATION_FOLLOWED_BY_JOURNALIST,
                'notifiable_id' => $idea->id,
                'notifiable_type' => get_class($idea),
                'model_id' => $follow->id,
                'model_type' => get_class($follow),
                'created_by_id' => $user->id,
                'roles' => [
                    Constants::TYPE_SUPER_ADMIN,
                    Constants::TYPE_ADMIN,
                    Constants::TYPE_SUB_ADMIN
                ],
                'user_id' => $sendToUsers
            ];
            Helper::sendNotification($data);

            return $this->success([
                "data" => new IdeaResource($idea),
                "message" => 'Followed Successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function unfollowIdea(Request $request)
    {
        $request->validate([
            "follow_id" => "required"
        ]);
        $idea = Idea::findorFail($request->follow_id);
        try {
            $model = Follow::where('user_id', Auth::user()->id)
                ->where('followable_id', $request->follow_id)
                ->where('state_id', Constants::STATE_ACTIVE)
                ->firstOrFail();

            if ($model) {
                $model->update([
                    'state_id' => Constants::STATE_DEACTIVATE
                ]);
                return $this->success([
                    "data" => new IdeaResource($idea),
                    "message" => 'Unfollowed Successfully.'
                ]);
                return $this->success('Unfollowed successfully');
            } else {
                return $this->failed('Something went wrong!!!');
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
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
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
