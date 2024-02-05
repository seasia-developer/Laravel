<?php

namespace App\Http\Controllers;

use App\Callout;
use App\Comment;
use App\Constant\Constants;
use App\Http\Helper\Helper;
use App\Http\Resources\CommentResource;
use App\Http\Resources\ReplyResource;
use App\Idea;
use App\Notifications\CommentMail;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CommentController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
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
            "comment_body" => 'required|string',
            "idea_id" => 'required',
            "name" => 'required|string',
            "email" => 'required|string',
            "type" => 'required'
        ]);
        $sendToUsers = [];
        $mediaHouseId = null;
        $data = [];
        try {
            $comment = new Comment();
            $comment->body = $request->comment_body;
            $comment->name = $request->name;
            $comment->email = $request->email;
            $user = Auth::guard('api')->user();
            $userId = null;
            $roles = [
                Constants::TYPE_SUPER_ADMIN,
                Constants::TYPE_SUB_ADMIN,
                Constants::TYPE_ADMIN
            ];

            if ($request->type == Constants::COMMENT_IDEA) {
                $model = Idea::findorFail($request->idea_id);
                $type = Constants::NOTIFICATION_NEW_COMMENTS_IDEA;
                if (!empty($model->claimed_by)) {
                    $sendToUsers[] = $model->claimed_by;
                    $mediaHouse = $model->claimedBy->myMediaHouse();
                    if (isset($mediaHouse[0]) && !empty($mediaHouse[0])) {
                        $sendToUsers[] = $mediaHouse[0];
                        $mediaHouseId =  $mediaHouse[0];
                    }
                }
            }
            if ($request->type == Constants::COMMENT_CALLOUT) {
                $model = Callout::findorFail($request->idea_id);
                $type = Constants::NOTIFICATION_NEW_COMMENTS_CALLOUT;
            }

            if ($user) {
                $comment->user()->associate($user);
                $userId = $user->id;
                if ($user->isAdmin()) {
                    $roles = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_SUB_ADMIN
                    ];
                }
                if ($user->isEditor()) {
                    $roles = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN
                    ];
                }
                if ($userId != $model->created_by) {
                    $sendToUsers[] = $model->created_by;
                }
            } else {
                $sendToUsers[] = $model->created_by;
            }
            // save data
            $model->comments()->save($comment);

            // mail initials
            $name = null;
            if (!empty($user)) {
                $name = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $user->slug . '">' . $user->name . '</a> ';
            } else {
                $name = $comment->name;
            }

            if ($request->type == Constants::COMMENT_IDEA) {
                $sub = " Comments on Idea ";
                $first = " " . $name . " has submitted information about the story idea, " . $model->title . ".  ";
                $url =   Constants::FRONTEND_URL . 'ideas/' . $model->slug;
                $btn = " VIEW SUBMISSION ";
                // to assigned journalist by MH
                if (!empty($model->assignedTo)) {
                    $this->customSendMail($model->assignedTo, (new CommentMail($sub, $sub, 'Hello', $first, "", $url, $btn)));
                }
                // to the one who have claimed the idea.
                if (!empty($model->claimedBy)) {
                    $this->customSendMail($model->claimedBy, (new CommentMail($sub, $sub, 'Hello', $first, "", $url, $btn)));
                }
                if (!empty($model->createdBy)) {
                    $this->customSendMail($model->createdBy, (new CommentMail($sub, $sub, 'Hello', $first, "", $url, $btn)));
                }
            }
            if ($request->type == Constants::COMMENT_CALLOUT) {
                $sub = " Comments on Callout ";
                $first = " " . $name . " has submitted a response to your callout , " . $model->title . " on Story Mosaic.  ";
                $url =   Constants::FRONTEND_URL . 'callouts/details/' . $model->slug;
                $btn = " VIEW RESPONSE ";
                // to created by callout
                if (!empty($model->createdBy)) {
                    $this->customSendMail($model->createdBy, (new CommentMail($sub, $sub, 'Hello', $first, "", $url, $btn)));
                }

                if ($model->createdBy->isJournalist()) {
                    $mediaHouseDetail = $model->createdBy->mediaHouse;
                    if (!empty($mediaHouseDetail)) {
                        $mediaHouseId = $mediaHouseDetail->id;
                        $this->customSendMail($mediaHouseDetail, (new CommentMail($sub, $sub, 'Hello', $first, "", $url, $btn)));
                    }
                }
            }

            $data = [
                'message' => "New Comment added!!! sending to admin|subadmin",
                'state_id' => 0,
                'type_id' =>  $type,
                'notifiable_id' =>  $model->id,
                'notifiable_type' => get_class($model),
                'model_id' =>  $comment->id,
                'model_type' => get_class($comment),
                'created_by_id' => $userId,
                'roles' => $roles,
                'user_id' => $sendToUsers,
                'is_alert' => true
            ];
            if (!empty($mediaHouseId)) {
                $data['user_id'] = $mediaHouseId;
            }

            Helper::sendNotification($data);

            return $this->success([
                'data' => new CommentResource($comment),
                'message' => 'Comment added Successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function replyStore(Request $request)
    {
        $request->validate([
            "comment_body" => 'required|string',
            "idea_id" => 'exists:ideas,id',
            "comment_id" => 'exists:comments,id'
        ]);
        try {
            $reply = new Comment();
            $reply->body = $request->get('comment_body');
            $reply->user()->associate($request->user());
            $reply->parent_id = $request->get('comment_id');
            $idea = Idea::find($request->get('idea_id'));
            $idea->comments()->save($reply);
            return $this->success([
                'data' => new ReplyResource($reply),
                'message' => 'Reply to Comment added Successfully.'
            ]);
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
    public function destroy(Request $request)
    {
        $model = Comment::findOrFail($request->id);
        try {
            $model->delete();
            return $this->success('Comment deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function comments(Request $request)
    {
        $request->validate([
            'type' => 'required',
            'id' => 'required'
        ]);
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $modelClass = Idea::class;
        if ($request->type == Constants::COMMENT_CALLOUT) {
            $modelClass = Callout::class;
        }
        $comment = Comment::where('commentable_id', $request->id)
            ->where('commentable_type', $modelClass)
            ->latest('id')
            ->paginate($limit);
        return $this->success([
            'data' => CommentResource::collection($comment),
            'message' => 'Comments Fetched Successfully.'
        ]);
    }
}
