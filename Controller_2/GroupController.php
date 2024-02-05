<?php

namespace App\Http\Controllers;

use App\Chat;
use App\Constant\Constants;
use App\Events\GroupEvent;
use App\Group;
use App\Http\Resources\ChatResource;
use App\Http\Resources\GroupResource;
use App\Idea;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $limit = $request->limit;
        if (is_numeric($limit)) {
            $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        } else {
            $limit = 10000;
        }

        $myGroups = 0;
        $list = Group::query();
        if ($request->filled('search')) {
            $search = $request->search;
            $list = $list->where(function ($query) use ($search) {
                $query->where('title', "LIKE", "%{$search}%");
                $query->orWhere(function ($query) use ($search) {
                    $query->whereHas('users', function ($q) use ($search) {
                        $q->where('name', 'LIKE', "%{$search}%");
                    });
                });
            });
        }
        if (!$user->isAdmin()) {
            //     $list = Group::query();
            // } else {
            $myGroups = $user->groups->pluck('id')->toArray();
            $list = $list->whereIn('id', $myGroups);
            $chat = Chat::select('group_id', DB::raw('MAX(created_at) as created_at'))
                ->whereIn('group_id', $myGroups)
                ->groupBy('group_id')
                ->orderBy('created_at', 'desc')
                ->pluck('group_id')
                ->toArray();
            if (!empty($chat)) {
                $list = $list->orderByRaw("FIELD(id ," . implode(',', $chat) . " ) ASC");
            }
        }
        // $list->where('state_id', Constants::STATE_ACTIVE);
        $list = $list->paginate($limit);
        return $this->success([
            'group_ids' => $myGroups,
            'data' => GroupResource::collection($list),
            'message' => 'Group List fetched successfully',
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
            'id' => 'required|integer'
        ]);
        $idea = Idea::findOrFail($request->id);
        if (!$request->filled('title')) {
            $request['title'] = $idea->title;
        }
        $user = Auth::user()->id;
        try {
            DB::beginTransaction();

            $model = new Group();
            $model->title = $request->title;
            $model->idea_id = $idea->id;
            $model->user_id = $user;
            $model->state_id = Constants::STATE_ACTIVE;
            $model->save();
            if ($request->filled('users')) {
                $usersArr = explode(",", $request->users);
                array_push($usersArr, $user);
                $users = array_filter($usersArr);
            } else {
                $users = $user;
            }
            $model->users()->attach($users);
            DB::commit();
            return $this->success([
                'data' => new GroupResource($model),
                'message' => 'Groud created : ' . $request->title
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
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
    public function destroy(Request $request)
    {
        $model = Group::findOrFail($request->id);
        try {
            $model->delete();
            return $this->success('Group deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    // if inivited user -> initiates -> means he is not in any group -> so create one with -> J | com | invi user
    // if journalist initiates -> 

    // if initiate_chat true -> directly initiate chat
    // loggedin_user_invited = if true -> collaborator and invited details alongwith, can get "invited_by", else other user 
    // chatroom_details -> group - id, user_id, idea_id
    // public function initiateChatRoom(Request $request)
    // {
    //     // if anyone initiates create chat room check for
    //     // if chat room exists, and a user clicks on initiate chat, than add that user to existing chat room
    //     // else, create a new chat room.
    //     $idea = Idea::findOrFail($request->id);
    //     $user = Auth::user();

    //     try {

    //         $addUsersToChatRoom = [];
    //         $addUsersToChatRoom[] = $idea->created_by; // add contributor
    //         if (!empty($idea->claimed_by)) {
    //             $addUsersToChatRoom[] = $idea->claimed_by; // add claimed by user id
    //         }
    //         if (!empty($idea->assigned_to)) {
    //             $addUsersToChatRoom[] = $idea->assigned_to; // add claimed by user id
    //         }
    //         $invitedUserIn =  false;
    //         $createGroup =  false;



    //         // if collaborator -> show and send this "invited_by" at collaborator page only
    //         if ($request->filled('invited_by') && !empty($request->invited_by)) {
    //             $invitedUserIn = true;
    //             $addUsersToChatRoom[] = $request->invited_by; // add invited user id, initiating chat
    //             $createGroup =  true;
    //         }

    //         if ($createGroup) {
    //             // create a group with the added users to chatroom 
    //             $group = new Group();
    //             $group->title = $idea->title;
    //             $group->user_id = $user->id;
    //             $group->idea_id = $idea->id;
    //             $group->state_id = Constants::STATE_ACTIVE;
    //             $group->type_id = $invitedUserIn ? Constants::CHAT_TYPE_GROUP : Constants::CHAT_TYPE_PRIVATE;
    //             $group->save();

    //             $chatNew = Chat::create([
    //                 'message' => 'newchatinitiated',
    //                 'group_id' => $group->id,
    //                 'type_id' => Constants::CHAT_TYPE_INITIATED
    //             ]);
    //             $pivotData = array_fill(0, count($addUsersToChatRoom), ['created_at' => Carbon::now()]);
    //             $syncData  = array_combine($addUsersToChatRoom, $pivotData);
    //             $group->users()->syncWithoutDetaching($syncData);

    //             if (!empty($addUsersToChatRoom)) {
    //                 $sendToUsers = array_unique($addUsersToChatRoom);
    //                 foreach ($sendToUsers as $userToSend) {
    //                     DB::table('chat_user')->insert(
    //                         [
    //                             'chat_id' => $chatNew->id,
    //                             'user_id' => $userToSend,
    //                             'is_read' => 0,
    //                             'group_id' => $group->id,
    //                             'created_at' => Carbon::now()->toDateTimeString()
    //                         ]
    //                     );
    //                     // broadcast(new GroupEvent($group->id, $userToSend));
    //                 }
    //             }
    //             return $this->success('Chat Room created successfully');
    //         } else {
    //             return $this->failed('Chat Room creation failed.');
    //         }
    //     } catch (\Illuminate\Database\QueryException $exception) {
    //         return $this->error($exception->errorInfo);
    //     }
    // }

    public function initiateChatRoom(Request $request)
    {
        $idea = Idea::findOrFail($request->id);
        $user = Auth::user();
        $newGroup = false;
        $addUsersToChatRoom = [];
        try {
            // check if group exists
            $group = Group::where('idea_id', $idea->id)
                ->first();

            if (empty($group)) {
                $group = new Group();
                $group->title = $idea->title;
                $group->user_id = $user->id;
                $group->idea_id = $idea->id;
                $group->state_id = Constants::STATE_ACTIVE;
                $group->type_id = Constants::CHAT_TYPE_GROUP;
                $group->save();
                $newGroup = true;
            }

            // if collaborator -> show and send this "invited_by" at collaborator page only
            if ($request->filled('invited_by') && !empty($request->invited_by)) {
                //check for if collaborator exists in the group
                $groupUserExists = DB::table('group_user')->where('group_id', $group->id)
                    ->where('user_id', $user->id)->first();
                if (empty($groupUserExists)) {
                    $newGroup = true;
                    $addUsersToChatRoom[] = $user->id; // add invited user id, initiating chat
                }
            }


            // if journalist initiates the chatroom
            if ($user->id == $idea->claimed_by || $user->id == $idea->assigned_to) {
                // check if invites are there and accepted, if yes add collaborators
                $invites = $idea->invites;
                if (!empty($invites)) {
                    foreach ($invites as $invite) {
                        $addUsersToChatRoom[] = $invite->user_id; // add all invitees who accepted this idea invites
                    }
                }
            }

            if ($newGroup) {
                $addUsersToChatRoom[] = $idea->created_by; // add contributor
                if (!empty($idea->claimed_by)) {
                    $addUsersToChatRoom[] = $idea->claimed_by; // add claimed by user id
                }
                // if (!empty($idea->assigned_to)) {
                //     $addUsersToChatRoom[] = $idea->assigned_to; // add claimed by user id
                // }
            }

            $pivotData = array_fill(0, count($addUsersToChatRoom), ['created_at' => Carbon::now(), 'state_id' => Constants::STATE_ACTIVE]);
            $syncData  = array_combine($addUsersToChatRoom, $pivotData);
            $group->users()->syncWithoutDetaching($syncData);

            if ($newGroup) {
                $chatNew = Chat::create([
                    'message' => 'newchatinitiated',
                    'group_id' => $group->id,
                    'type_id' => Constants::CHAT_TYPE_INITIATED
                ]);

                if (!empty($addUsersToChatRoom)) {
                    $sendToUsers = array_unique($addUsersToChatRoom);
                    foreach ($sendToUsers as $userToSend) {
                        DB::table('chat_user')->insert(
                            [
                                'chat_id' => $chatNew->id,
                                'user_id' => $userToSend,
                                'is_read' => 0,
                                'group_id' => $group->id,
                                'created_at' => Carbon::now()->toDateTimeString()
                            ]
                        );
                        broadcast(new GroupEvent($group->id, $userToSend));
                    }
                }
            }
            return $this->success('Chat Room created successfully',$group->id);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
