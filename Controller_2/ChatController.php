<?php

namespace App\Http\Controllers;

use App\Chat;
use App\Constant\Constants;
use App\Group;
use App\GroupUser;
use App\Http\Helper\Helper;
use App\Http\Resources\ChatResource;
use App\Idea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
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
        try {
            $user = Auth::user();
            $chatModel = Chat::where('user_id', $user->id)->where("created_at", '>=', $user->created_at);
            if ($request->filled('search')) {
                $chatModel->where('message', "LIKE", "%{$request->search}%");
            }
            $chatModel = $chatModel->latest('id')->orderBy('id', 'ASC')->paginate($limit);
            $data = ChatResource::collection($chatModel);

            return $this->success([
                'data' => $data,
                'message' => 'Chat messages fetched successfully',
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function adminChatList(Request $request)
    {
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        try {
            $user = Auth::user();
            $chatModel = Chat::where('group_id', $request->group_id);
            if ($request->filled('search')) {
                $chatModel->where('message', "LIKE", "%{$request->search}%");
            }
            $chatModel = $chatModel->latest('id')->orderBy('id', 'ASC')->paginate($limit);
            $data = ChatResource::collection($chatModel);
            return $this->success([
                'data' => $data,
                'message' => 'Chat messages fetched successfully',
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function conversation(Request $request)
    {
        $request->validate([
            'id' => 'required'
        ]);
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $model = Group::findOrFail($request->id);
        $data  = [];
        try {
            $user = Auth::user();
            $findMe = GroupUser::where('user_id', $user->id)
                ->where('group_id', $model->id)
                ->first();
            if (!empty($findMe)) {
                // $chatModel = Chat::where('group_id', $request->id)->where("created_at", '>=', $findMe->created_at);
                $chatModel = Chat::where('group_id', $request->id);
                if ($request->filled('search')) {
                    $chatModel->where('message', "LIKE", "%{$request->search}%");
                }
                $chatModel = $chatModel->latest('id')->orderBy('id', 'ASC')->paginate($limit);
                $data = ChatResource::collection($chatModel);
                DB::table('chat_user')
                    ->where('user_id', $user->id)
                    ->where('group_id', $model->id)
                    ->update(['is_read' => 1]);
            }

            return $this->success([
                'data' => $data,
                'message' => 'Chat messages fetched successfully',
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
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
        $user = Auth::user();
        $sendToUsers = [];
        $request->validate([
            'group_id' => 'required|integer',
            'message' => 'required|string'
        ]);
        $group = Group::findOrFail($request->group_id);
        $idea = Idea::findOrFail($group->idea_id);
        try {
            DB::beginTransaction();
            $model = new Chat();
            $model->message = $request->message;
            $model->type_id  = Constants::CHAT_TYPE_GROUP;
            $model->user_id = $user->id;
            $model->group_id = $request->group_id;
            $model->state_id = Constants::STATE_ACTIVE;
            $model->save();
            $sendToUsers[] = $idea->created_by;
            //Message received on any claimed Idea/callout ==> to Journalist
            if (!$user->isJournalist() && !empty($idea->claimed_by)) {
                $sendToUsers[] = $idea->claimed_by;
                $myMediaHouse = $user->myMediaHouse();
                if (isset($myMediaHouse[0]) && !empty($myMediaHouse[0])) {
                    $sendToUsers[] = $myMediaHouse[0];
                }
            }
            if ($user->isJournalist()) {
                $sendToUsers[] = $idea->created_by;
            }
            $data = [
                'message' => "New Message Received",
                'type_id' => Constants::NOTIFICATION_CHAT_NEW,
                'notifiable_id' => $idea->id,
                'notifiable_type' => get_class($idea),
                'model_id' => $model->id,
                'model_type' => get_class($model),
                'created_by_id' => $user->id,
                'user_id' => $sendToUsers,
                'is_alert' => true
            ];
            Helper::sendNotification($data);
            DB::commit();
            return $this->success([
                'data' => $model,
                'message' => 'Message sent'
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
        $model = Chat::findOrFail($request->id);
        try {
            $model->delete();
            return $this->success('Message deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function searchCoversation(Request $request)
    {
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $request->validate([
            'id' => 'required|integer',
            // 'search' => 'required|string'
        ]);
        Group::findOrFail($request->id);
        try {
            $chatModel = Chat::where('group_id', $request->id)
                ->where('message', "LIKE", "%{$request->search}%");
            if ($request->filled('search') && !empty($request->search)) {
                $chatModel = $chatModel->where('message', "LIKE", "%{$request->search}%");
            }
            $chatModel = $chatModel->latest()
                ->paginate($limit);
            return $this->success([
                'data' => ChatResource::collection($chatModel),
                'message' => 'Chat messages fetched successfully',
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
