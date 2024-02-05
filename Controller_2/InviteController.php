<?php

namespace App\Http\Controllers;

use App\Chat;
use App\Constant\Constants;
use App\Events\GroupEvent;
use App\Group;
use App\Http\Helper\Helper;
use App\Http\Resources\InviteResource;
use App\Idea;
use App\Invite;
use App\Notifications\InviteMailLogin;
use App\Notifications\InviteMailSignUp;
use App\Notifications\InviteResponse;
use App\Revision;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InviteController extends Controller
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
        $list = Invite::latest('id');
        $countAll = 0;
        if ($request->filled('type')) {
            if ($user->isJournalist() || $user->isMediaHouse()) {
                if ($request->type == Constants::INVITATION_SENT) {
                    $list = $list->where('created_by_id', $user->id);
                }
                if ($request->type == Constants::INVITATION_RECIEVED) {
                    $list = $list->where('user_id', $user->id);
                }

                if ($request->filled('allcount') && $request->allcount) {
                    $countAll = Invite::where(function ($query) use ($user) {
                        $query->where('created_by_id', $user->id)
                            ->orWhere('user_id', $user->id);
                    })->count();
                }
            }
        }
        $list = $list->has('idea');
        $count = $list->count();
        if ($request->filled('search')) {
            $search = $request->search;
            $list = $list->where(function ($query) use ($search) {
                $query->whereHas('idea', function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%");
                })
                    ->orwhereHas('createdBy', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
            });
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
        $list->withCount('idea');
        $list = $list->paginate($limit);
        return $this->success(
            [
                'countAll' => $countAll,
                'count' => $count,
                'data' => InviteResource::collection($list),
                'message' => 'Invite List fetched successfully'
            ]
        );
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
        $first = '';
        $btn = '';
        $url = '';
        $second = '';
        $sendNotification = false;
        $request->validate([
            'name' => "string",
            'email' => 'required_if:type_id,2|string',
            'message' => 'required|string',
            'type_id' => 'required|integer',
            'idea_id' => 'required|exists:ideas,id',
            'user_id' => 'required_if:type_id,1|exists:users,id',
            'url' => 'required|string'
        ]);
        $auth = Auth::user();
        $exists = null;
        if ($request->filled('user_id')) {
            $exists = Invite::where('created_by_id', $auth->id)->where('idea_id', $request->idea_id)
                ->where('user_id', $request->user_id)->first();
        }
        if ($request->filled('email')) {
            $exists = Invite::where('created_by_id', $auth->id)->where('idea_id', $request->idea_id)
                ->where('email', $request->email)->first();
        }
        if (!empty($exists)) {
            return $this->failed('You have already Invited this user on this Idea.');
        }

        $idea = Idea::findOrFail($request->idea_id);
        $request['created_by_id'] =  $auth->id;
        $names = null;
        try {
            $model = new Invite();
            $model->name = $request->name;
            $model->email = $request->filled('email') ? $request->email : null;
            $model->message = $request->message;
            $model->type_id = $request->type_id;
            $model->idea_id = $request->idea_id;
            $model->user_id = $request->filled('user_id') ? $request->user_id : null;
            $model->created_by_id = $request->created_by_id;
            $model->save();

            if ($model->type_id == Constants::INVITE_TYPE_MAIL) {
                $userTo = User::where('email', $model->email)->first();
                if (!empty($userTo)) {
                    $names = $userTo->name;
                    $sendNotification = true;
                } else {
                    $userData = User::where('id',$auth->id)->first();
                    $userName = $userData->name;
                    $names = $model->email;
                    $first = ' ' . $userName . ' has invited you to collaborate on the idea '. $idea->title . '.';
                    $second = ' ' . $request->message;
                    $btn = ' SEE STORY IDEA ';
                    $url = Constants::FRONTEND_URL . 'ideas/' . $idea->slug;
                    $this->customSendMail($model, (new InviteMailLogin(
                    ' Invitation ',
                    ' Invitation for an Idea ',
                    'Hello',
                    $first,
                    $second,
                    $url,
                    $btn
                    )));
                    //$this->customSendMail($model, (new InviteMailSignUp($request->url, $auth->name)));
                }
            }
            if ($model->type_id == Constants::INVITE_TYPE_NAME) {
                $userTo = User::where('id', $model->user_id)->first();
                if (!empty($userTo)) {
                    $names = $userTo->name;
                    $sendNotification = true;
                } else {
                    return $this->failed('No data found for Invited User.');
                }
            }

            if ($sendNotification) {
                // send notifications
                $data['notifiable_id'] = $idea->id;
                $data['notifiable_type'] =  get_class($idea);
                $data['model_id'] = $model->id;
                $data['model_type'] =  get_class($model);
                $data['created_by_id'] = $model->created_by_id;
                $data['state_id'] = 0;
                $data['message'] = "Journalist invited user";
                $data['type_id'] = Constants::NOTIFICATION_JOURNALIST_INVITED_USER;
                $data['user_id'] = $model->user_id;
                Helper::sendNotification($data);

                // send emails now
                $name = ' <a href="' . Constants::FRONTEND_URL . "profile/" .  $model->createdBy->slug . '">' . $model->createdBy->name . '</a> ';
                if ($model->createdBy->isJournalist()) {
                    $mediaHouseRel = $model->createdBy->mediaHouse;
                    if (!empty($mediaHouseRel)) {
                        $name .= ' / <a href="' . Constants::FRONTEND_URL . "profile/" .  $mediaHouseRel->slug . '">' . $mediaHouseRel->name . '</a> ';
                    }
                }
                // $name = $userTo->name;
                // if journalist
                if ($userTo->isJournalist()) {
                    $first = ' ' . $name . ' has invited you to collaborate on the idea '. $idea->title . '.';
                    $second = ' ' . $request->message;
                    $btn = ' SEE STORY IDEA ';
                    $url = Constants::FRONTEND_URL . 'ideas/' . $idea->slug;
                }
                // if media house
                if ($userTo->isMediaHouse()) {
                    $first =  ' ' . $name . ' has invited you to join Story Mosaic, a platform that allows
                         community members to share story ideas with local journalists. ';
                    $btn = ' LEARN MORE ';
                    $url =  Constants::FRONTEND_URL . 'how-it-works';
                }

                // comm, user
                if (!empty($userTo->isCommUser())) {
                    $first =  ' ' . $name . ' has invited you to join Story Mosaic,
                         a platform that allows you to share story ideas with local journalists in your community. ';
                    $btn = ' LEARN MORE ';
                    $url =  Constants::FRONTEND_URL . 'how-it-works';
                }

                $this->customSendMail($userTo, (new InviteMailLogin(
                    ' Invitation ',
                    ' Invitation for an Idea ',
                    'Hello',
                    $first,
                    $second,
                    $url,
                    $btn
                )));
            }

            // store revision as funding initiated
            $revision = [
                'user_id' => Auth::user()->id,
                'revisionable_id' => $idea->id,
                'revisionable_type' => get_class($idea),
                'key_string' => "Invitation sent to " . $names . ' by ' . $auth->name,
                'type' => Constants::VERSION_IDEA
            ];
            Revision::create($revision);
            //revision created
            return $this->success([
                'data' => $model,
                'message' => 'Invite sent successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function storeInvitation(Request $request)
    {
  
        $request->validate([
            'email' => 'required|string',
            'url' => 'required|string'
        ]);
        $auth = Auth::user();
        $exists = User::where('email', $request->email)->first();
        if (!empty($exists)) {
            return $this->failed('User already exists!');
        }
        try {
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
            if (in_array($auth->role_id, $roleIdCheck)) {
                $request->validate([
                    'type' => 'required'
                ]);
            } elseif ($auth->role_id == Constants::TYPE_MEDIA_HOUSE) {
                $request['type'] = Constants::TYPE_JOURNALIST;
            } elseif ($auth->role_id == Constants::TYPE_JOURNALIST) {
                $request['type'] = Constants::TYPE_JOURNALIST;
            } else {
                $request['type'] = Constants::TYPE_COMMUNITY_USER;
            }
            $model = Invite::updateOrCreate(
                [
                    'email' =>  $request->email,
                    'type_id' =>  $request->type
                ],
                ['created_by_id' => $auth->id]
            );

            $userTo = User::where('email', $request->email)->first();
            if (!empty($userTo)) {
                // send notifications
                $data['model_id'] = $model->id;
                $data['model_type'] =  get_class($model);
                $data['created_by_id'] = $model->created_by_id;
                $data['state_id'] = 0;
                $data['message'] = "Invitation to user";
                $data['type_id'] = Constants::NOTIFICATION_INVITATION_TO_USER;
                $data['user_id'] = $model->user_id;
                Helper::sendNotification($data);
            } else {
                $this->customSendMail($model, (new InviteMailSignUp($request->url, $auth->name)));
            }
            return $this->success([
                'data' => $model,
                'message' => 'Invitation sent successfully.'
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
        $model = Invite::findOrFail($request->id);
        try {
            $model->delete();
            return $this->success('Invite deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function response(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'state_id' => 'required|integer'
        ]);
        $model = Invite::findOrFail($request->id);
        $idea = Idea::findOrFail($model->idea_id);
        $response = null;
        $email = null;
        try {
            $users = [];
            if ($model->state_id != $request->state_id) {
                if (!empty($model->email)) {
                    $email = $model->email;
                } else {
                    $invitedUser = $model->user;
                    if (!empty($invitedUser)) {
                        $email = $model->user->email;
                    }
                }
                $model->update($request->all());

                if ($model->state_id == Constants::INVITE_ACCEPTED) {
                    $response = "Accepted";
                    $notificationType = Constants::NOTIFICATION_INVITATION_ACCEPTED;
                }
                if ($model->state_id == Constants::INVITE_DECLINED) {
                    $response = "Rejected";
                    $notificationType = Constants::NOTIFICATION_INVITATION_REJECTED;
                }

                if (!empty($notificationType)) {
                    $data = [
                        'message' => "Invitation Response",
                        'type_id' => $notificationType,
                        'notifiable_id' => $idea->id,
                        'notifiable_type' => get_class($idea),
                        'model_id' => $model->id,
                        'model_type' => get_class($model),
                        'created_by_id' => Auth::user()->id,
                        'user_id' => $model->created_by_id,
                        'is_alert' => true
                    ];
                    Helper::sendNotification($data);
                }
                if (!empty($response)) {
                    $this->customSendMail($model->createdBy, (new InviteResponse($idea->title, $request->url, $response, $email)));
                    // store revision as funding initiated
                    $revision = [
                        'user_id' => Auth::user()->id,
                        'revisionable_id' => $idea->id,
                        'revisionable_type' => get_class($idea),
                        'key_string' => "Invitation " . $response . " by " . $model->user->name,
                        'type' => Constants::VERSION_IDEA
                    ];
                    Revision::create($revision);
                    //revision created
                }
                $data = [];
                $data['data'] = $model;
                $data['message'] = 'Status updated successfully.';
                return $this->success($data);
            }
            return $this->success('Already in the same state');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
