<?php

namespace App\Http\Controllers;

use App\Chat;
use App\User;
use App\Constant\Constants;
use App\Events\GroupEvent;
use App\Events\UserBlock;
use App\Funding;
use App\GroupUser;
use App\Http\Helper\Helper;
use App\Http\Resources\UserResource;
use App\Http\Resources\InviteResource;
use App\Idea;
use App\Invite;
use App\Notifications\IdeaMail;
use App\Notifications\PasswordResetSuccess;
use App\Notifications\InviteMailSignUp;
use App\Notifications\UserMail;
use App\Revision;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\EmailNotificationContent;
use Newsletter;

class UserController extends Controller
{

    public function getUser()
    {
        $user = User::findOrFail(Auth::user()->id);
        // if ($user->state_id == Constants::STATE_ACTIVE) {
        return $this->success([
            'data' => new UserResource($user)
        ]);
        // }
        return $this->failed("User is not active.");
    }

    public function list(Request $request)
    {
      
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $auth = Auth::user();
        $user = User::where('role_id', $request->type);

      if (($request->type == Constants::TYPE_JOURNALIST) && ($request->unapproved_journalist == Constants::UNVERIFIED_JOURNALIST)) {
            if ($auth->isMediaHouse()) {
                $user = $user->where('media_house_id', $auth->id);
            }
            if ($request->filled('unapproved_journalist') && !empty($request->unapproved_journalist) ) {
                $user = $user->whereNotNull('email_verification_token');
            } else {
                if ($auth->isMediaHouse() || $auth->isAdmin()) {
                    $user = $user->whereNotNull('email_verification_token');
                }
            }
        }

        if (($request->type == Constants::TYPE_MEDIA_HOUSE) && ($request->unapproved_journalist == Constants::UNVERIFIED_MEDIAHOUSE)) {
            if ($auth->isMediaHouse()) {
                $user = $user->where('media_house_id', $auth->id);
            }
            if ($request->filled('unapproved_journalist') && !empty($request->unapproved_journalist)) {
                $user = $user->whereNotNull('email_verification_token');
            } else {
                if ($auth->isMediaHouse()) {
                    $user = $user->whereNotNull('email_verification_token');
                }
            }
        }




        if (($request->type == Constants::TYPE_JOURNALIST) && ($request->unapproved_journalist ==  Constants::STATE_ACTIVE)) {

            if ($auth->isMediaHouse()) {
                $user = $user->where('media_house_id', $auth->id);
            }
            if ($request->filled('unapproved_journalist') && !empty($request->unapproved_journalist) ) {
                $user = $user->where('state_id', Constants::STATE_DEACTIVATE)->where('email_verification_token',"=","");
            } else {
                if ($auth->isMediaHouse() || $auth->isAdmin()) {
                    $user = $user->where('state_id', '!=', Constants::STATE_DEACTIVATE)->where('email_verification_token',"=","");
                }
            }
        }

        if (($request->type == Constants::TYPE_MEDIA_HOUSE) && ($request->unapproved_journalist ==  Constants::STATE_ACTIVE)) {
            if ($auth->isMediaHouse()) {
                $user = $user->where('media_house_id', $auth->id);
            }
            if ($request->filled('unapproved_journalist') && !empty($request->unapproved_journalist)) {
                $user = $user->where('state_id', Constants::STATE_DEACTIVATE)->where('email_verification_token',"=","");
            } else {
                if ($auth->isMediaHouse()) {
                    $user = $user->where('state_id', '!=', Constants::STATE_DEACTIVATE)->where('email_verification_token',"=","");
                }
            }
        }

        if ($auth->isMediaHouse())
        {
            $user = $user->where('media_house_id', $auth->id)->where('state_id', '!=', Constants::STATE_DEACTIVATE);
        }

        if ($request->has('search')) {
            $user = $user->where(function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->search}%")
                    ->orWhere('first_name', 'like', "%{$request->search}%")
                    ->orWhere('last_name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        if ($request->filled('month') && $request->filled('year')) {
            $month = Carbon::createFromDate($request->year, $request->month);
            $start = $month->startOfMonth()->toDateTimeString();
            $end = $month->endOfMonth()->toDateTimeString();
            $user->whereBetween('created_at', [
                $start,
                $end
            ]);
        }
        if($request->filled('status')){
            $user = $user->where('state_id',$request->status);
        }

        $user = $user->orderby('id', 'desc')->latest()
            ->paginate($limit);

        return $this->success([
            'data' => UserResource::collection($user),
            'message' => 'User List fetched successfully'
        ]);
    }
    public function invitedList(Request $request)
    {
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $auth = Auth::user();
        $InviteUser = Invite::where([ 'state_id' => Constants::STATE_DEACTIVATE, 'type_id' => $request->type, 'idea_id' => null, 'user_id' => null ])->where('name', '!=', null)->where('organization_name', '!=', null);

        if ($request->has('search')) {
            $InviteUser = $InviteUser->where(function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->search}%")
                    ->orWhere('organization_name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            });
        }
        if ($request->filled('month') && $request->filled('year')) {
            $month = Carbon::createFromDate($request->year, $request->month);
            $start = $month->startOfMonth()->toDateTimeString();
            $end = $month->endOfMonth()->toDateTimeString();
            $InviteUser->whereBetween('created_at', [
                $start,
                $end
            ]);
        }


        if ($auth->isSuperAdmin() || $auth->isAdmin() || $auth->isEditor()) {
            $InviteUser = $InviteUser->orderby('id', 'desc')->latest()
            ->paginate($limit);
        }else{
            return $this->failed('You dont have access to this data.');
        }
        
        return $this->success([
            'data' => InviteResource::collection($InviteUser),
            'message' => 'Invite User List fetched successfully'
        ]);

    }

    public function journalistList(Request $request)
    {
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;

        $user = User::where([ 'role_id'=>Constants::TYPE_JOURNALIST, 'state_id' =>Constants::STATE_ACTIVE ])->whereNotNull('email_verification_token')->orderBy('name', 'ASC')->paginate($limit);

        return $this->success([
            'data' => UserResource::collection($user),
            'message' => 'User List fetched successfully'
        ]);
    }

    public function view($id)
    {
        $userData = User::findorFail($id);
        $user = Auth::guard('api')->user();
        $response = false;
        if ($user) {
            if ($user->id == $userData->id) {
                $response = true;
            } else {
                if ($user->isAdmin() || $user->isSuperAdmin() || $user->isEditor()) {
                    $response = true;
                }
                if ($userData->role_id == Constants::TYPE_JOURNALIST && $user->isMediaHouse() && $userData->media_house_id == $user->id) {
                    $response = true;
                }
            }
        } else {
            $response = true;
        }
        if ($response) {
            return $this->success([
                'data' => new UserResource($userData)
            ]);
        } else {
            return $this->failed('You dont have access to this user data.');
        }
    }
    public function showSlug($slug)
    {
        try {
            $user = User::where('slug', $slug)->first();
            if(!empty($user)){
                if (!$user->viewPendingProfileAccess()) {
                    return $this->failed('This user is not verified');
                }
            }
            if (!empty($user)) {
                return $this->success([
                    'data' => new UserResource($user)
                ]);
            }
            return $this->failed('User does not exists!!!');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function updateProfile(Request $request)
    {
        $sendMail = false;
        if ($request->filled('id')) {
            $user = User::findOrFail($request->id);
        } else {
            $user = Auth::user();
        }
        $logoutData = $user;
        $oldEmail = $user->email;
        $logout = false;
        try {
            if($request->filled('email_confirmation')){
                if($request->email_confirmation != $request->email){
                    return $this->failed('Email does not match!');
                }
            }
            $emailExists = User::withTrashed()->where('email', $request->email)->where('id', '!=', $user->id)->get()->count();
            if($emailExists > 0){
                return $this->failed('This email has already been taken.');
            }
            if ($request->filled('name')) {
                if ($request->name != $user->getOriginal('name')) {
                    $request['slug'] = $this->createSlug(User::class, $request->name, $user->id);
                }
            }
            if ($request->filled('password')) {
                $btn = "";
                $url = "";
                $password = bcrypt($request->password);
                $request['password'] = $password;
                //admin
                if ($user->isAdmin()) {
                    $first = " Your Story Mosaic password has been updated. ";
                    $second = "";
                    $btn = " GO TO STORY MOSAIC ";
                    $url =  Constants::FRONTEND_URL;
                } else {
                    $first = " This email is to confirm that your Story Mosaic password has been changed. ";
                    $second = " If you believe you are receiving this message in error please contact us at admin@storymosaic.org. ";
                }
                $sendMail = true;
            }
            if($request->filled('new_media_house') == "on" && $request->organization_name != "") {
                $mediahouse = $request->organization_name;
                $model = Invite::updateOrCreate(
                    [
                        'name' => $request->name,
                        'email' =>  $request->email,
                        'organization_name' =>  $request->organization_name,
                        'type_id' =>  Constants::TYPE_MEDIA_HOUSE
                    ],
                    ['created_by_id' => Auth::id()]
                );
                $userTo = User::where('email', $request->organization_email)->first();
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
                    $this->customSendMail($model, (new InviteMailSignUp(env('FRONTEND_URL')."invite-signup/".$request->organization_email."/3", $request->name)));
                }
                $user->update([
                    'media_house_id' => null,
                    'media_house_name' => $request->organization_name,
                    'is_freelancer' => Constants::STATE_DEACTIVATE
                ]);
                return $this->success([
                    'data' => new UserResource($user),
                    'message' => 'User Profile Updated Successfully'
                ]);
            }
            if ($request->filled('new_media_house') == "on" && $request->organization_name == "") {
                $user->update([
                    'media_house_id' => $request->media_house_id 
                ]);
                return $this->success([
                    'data' => new UserResource($user),
                    'message' => 'User Profile Updated Successfully'
                ]);
            }
            if($request->is_freelancer){
                $user->update([
                    'is_freelancer' => Constants::STATE_ACTIVE,
                    'media_house_id'=> NULL,
                    'media_house_name'=> NULL
                ]);
                return $this->success([
                'data' => new UserResource($user),
                'message' => 'User Profile Updated Successfully'
            ]);
            }
            if($request->media_house_id && $request->media_house_name){
                $getmediahouse = User::where('id', $request->media_house_id)->first();
                $user->update([
                    'is_freelancer' => Constants::STATE_DEACTIVATE,
                    'media_house_id'=> $request->media_house_id,
                    'media_house_name'=> $getmediahouse->media_house_name
                ]);
                return $this->success([
                'data' => new UserResource($user),
                'message' => 'User Profile Updated Successfully'
            ]);
            }
            $userUpdated = $user->update($request->all());
            if($userUpdated){
                if($oldEmail !== $request->email && $logoutData->role_id == Constants::TYPE_JOURNALIST){
                $tokenResult = $logoutData->createToken('Personal Access Token');
                $journalistEmailContent = EmailNotificationContent::where(['slug'=>'user-sign-up', 'user_type'=>'Journalist', 'status'=>Constants::STATE_ACTIVE])->first();
                $frontend =  Constants::FRONTEND_URL . 'how-it-works';
                $heading = str_replace("<JOURNALIST_USERNAME>", "$logoutData->name", $journalistEmailContent->heading);
                $greeting = str_replace($journalistEmailContent->greeting_tag, "<a href='#' style='color: #63b260;'>" . $logoutData->name . "</a>", $journalistEmailContent->greeting);
                $first = str_replace("<JOURNALIST_USERNAME>", "$logoutData->name", "<p>".$journalistEmailContent->content."<p>");
                $second = ' <p>  In the meantime, you can learn more about <a href=' . $frontend . '>how the project works</a>.  </p> ';
                $btn = str_replace("<JOURNALIST_USERNAME>", "$logoutData->name", $journalistEmailContent->button_name);
                $url = $request->verify_url . $tokenResult->accessToken;
                $this->customSendMail($logoutData, (new UserMail(' Verify Your Email Address ', $heading, $greeting,  $first, $second, $url, $btn)));
                $logout = true;
                $logoutData->update([
                    'email_verified_at'=> null,
                    'email_verification_token' => $tokenResult->accessToken
                ]);

            }
            }
            if ($sendMail) {
                $this->customSendMail($user, (new PasswordResetSuccess($first, $second, $url, $btn)));
            }
            return $this->success([
                'logout'=> $logout,
                'data' => new UserResource($user),
                'message' => 'User Profile Updated Successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function updateProfilePic(Request $request)
    {
        $user = Auth::user();
        if ($user->isAdmin()) {
            if ($request->filled('id')) {
                $model = User::where('id', $request->id)->first();
                if (!empty($model)) {
                    $user =  $model;
                }
            }
        }
        try {
            $profile_img_path = '';
            $old_img_path = '';
            if (!empty($user->profile_image)) {
                $old_img_path = $user->profile_image;
            }
            if ($request->file('profile_image')) {
                $profile_img_path = Helper::saveImage($request->profile_image, 'user-profile', $user->id);
            }

            if ($profile_img_path != '') {
                $user->profile_image = $profile_img_path;
            } else {
                $user->profile_image = $old_img_path;
            }
            $user->update([
                'profile_image'
            ]);
            Helper::removeImage($old_img_path);
            return $this->success([
                'message' => 'Profile pic updated successfully',
                'data' => new UserResource($user)
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function changePassword(Request $request)
    {
        $btn = "";
        $url = "";
        $user = Auth::user();
        try {
            $password = bcrypt($request->password);
            $user->update([
                'password' => $password
            ]);
            //admin
            if ($user->isAdmin()) {
                $first = " Your Story Mosaic password has been updated. ";
                $second = "";
                $btn = " GO TO STORY MOSAIC ";
                $url =  Constants::FRONTEND_URL;
            } else {
                $first = " This email is to confirm that your Story Mosaic password has been changed. ";
                $second = " If you believe you are receiving this message in error please contact us at admin@storymosaic.org. ";
            }
            $this->customSendMail($user, (new PasswordResetSuccess($first, $second, $url, $btn)));
            return $this->success('Password Changed Successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function delete($id)
    {
        $url =  Constants::FRONTEND_URL;
        $btn = " VIEW ACCOUNT ";
        $loggeduser = Auth::user();
        $user = User::findOrFail($id);
        $user_email = $user->email;
        try {
            $url .= 'profile/' . $user->slug;
            broadcast(new UserBlock(Constants::STATE_REJECTED, $id));
            $user->delete();
            // if admin deletes the account, send mail to user
            if ($loggeduser->isAdmin()) {
                $first = " Your Story Mosaic account has been deactivated for a violation of terms of service. ";
                $second = " If you have questions, please contact us at admin@storymosaic.org. ";
                $this->customSendMail($user, (new UserMail(" Account Deactivated ", " Account Deactivated  ", "Hello", $first, $second, '', '')));
            } else {
                // send to the user
                $first = " Your Story Mosaic account has been deactivated. ";
                $second = " If you believe you are receiving this message in error please contact us at admin@storymosaic.org. ";
                $this->customSendMail($user, (new UserMail(" Account Deactivated ", " Account Deactivated  ", "Hello", $first, $second, '', '')));

                $admins = User::where('role_id', Constants::TYPE_ADMIN)->get();
                if (!empty($admins) && ($admins->count() > 0)) {
                    // send to admin
                    foreach ($admins as $admin) {
                        $adminfirst = " " . $user->name . " has deactivated their Story Mosaic account. ";
                        $this->customSendMail($admin, (new UserMail(" Account Deactivated ", " Account Deactivated  ", "Hello", $adminfirst, '', $url, $btn)));
                    }
                }
            }
            // if user delete the account
            return $this->success('User deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function mailSubscribe(Request $request)
    {
        $request->validate([
            'email' => 'required|string',
            'name' =>  'required|string'
        ]);

        try {
            if (!Newsletter::isSubscribed($request->email)) {
                $data =  Newsletter::subscribeOrUpdate($request->email, ['FNAME' => $request->name]);
            } else {
                return $this->success('You have already Subscribed');
            }
            $error = Newsletter::getLastError();
            if (!empty($error)) {
                return $this->error($error);
            }
            if (!empty($data)) {
                return $this->success('Mail Subscribed successfully');
            }
            return $this->error('Something Went Wrong');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function myJournalistList(Request $request)
    {
        $id = null;
        if ($request->filled('claim_media_house_id') && !empty($request->claim_media_house_id)) {
            $id = $request->claim_media_house_id;
        } else {
            $user = Auth::user();
            if ($user->isMediaHouse()) {
                $id = Auth::user()->id;
            }
        }
        if (!empty($id)) {
            $model = User::where('media_house_id', $id)->where('state_id', Constants::STATE_ACTIVE)->get();
            return $this->success(['data' => UserResource::collection($model)]);
        } else {
            return $this->error('Required Params not sent.');
        }
    }

    public function journalistsSearch($search)
    {
        $model = User::select('id', 'name', 'profile_image')
            ->where('id', '!=', Auth::user()->id)
            ->where('role_id', Constants::TYPE_JOURNALIST)
            ->where('state_id', Constants::STATE_ACTIVE)
            ->where(function ($q) use ($search) {
                $q->orwhere('name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            })->get();
        return $this->success(['data' => $model]);
    }

    public function mediaHouseSearch($search)
    {
        try {
        $model = User::select('id', 'name', 'media_house_name', 'profile_image');
        $user = Auth::guard('api')->user();
        if ($user) {
            $model = $model->where('id', '!=', $user->id);
        }
        $model = $model->where('role_id', Constants::TYPE_MEDIA_HOUSE)
            ->where('state_id', Constants::STATE_ACTIVE)
            ->where(function ($q) use ($search) {
                $q->orwhere('name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('media_house_name', 'like', "%{$search}%");
            })->get();
        return $this->success(['data' => $model]);
     }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function inviteMediaHouseSearch($search)
    {
        try {
        $model = Invite::select('id', 'name', 'email', 'organization_name');
        $model = $model->where('type_id', Constants::TYPE_MEDIA_HOUSE)
            ->where('state_id', Constants::STATE_DEACTIVATE)
            ->where('organization_name', '!=', null)
            ->where(function ($q) use ($search) {
                $q->orwhere('name', 'like', "%{$search}%")
                    ->orWhere('organization_name', 'like', "%{$search}%");
            })->get();
        return $this->success(['data' => $model]);
      }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function enableNotification(Request $request)
    {
        $user = Auth::user();
        $request->validate([
            'enable_notification' => 'required'
        ]);

        try {
            $user->update([
                'enable_notification' => $request->enable_notification
            ]);
            if ($request->enable_notification == Constants::STATE_ACTIVE) {
                return $this->success('Enabled Notification Successfully');
            }
            if ($request->enable_notification == Constants::STATE_DEACTIVATE) {
                return $this->success('Disabled Notification Successfully');
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function closeAccount(Request $request)
    {
        // Contributor:
        // Contributor closing an account (User will get blocked):
        // The notification+mails goes to any journalist who is currently involved in the idea 
        // submitted by the contributor.
        // Contributor's profile would continue to exist on the portal
        // The contributor would not be able to login into the portal
        // The contributor can request reactivation of the profile via email (Admin will unblock the account)
        // Chat boards will become inactive once the account closing happens (to be discussed with the technical team)
        // Any idea which is being followed by the contributor, the notification will get stopped.
        // All notifications will potentially get stopped post account closing.
        $request->validate([
            'account_close_notes' => 'required'
        ]);
        $auth = Auth::user();
        $msg = "Account has been closed successfully!";
        try {
            // Contributor:
            if ($auth->isCommUser()) {
                // claimed ideas 
                $createdIdeas = $auth->ideas->where('status', Constants::IDEA_CLAIMED);
                // get claimed by details and send notification
                if (!empty($createdIdeas)) {
                    foreach ($createdIdeas as $createdIdea) {
                        // Chat boards will become inactive once the account closing happens 
                        // (to be discussed with the technical team)
                        DB::table('group_user')->where('user_id', $auth->id)
                            ->update(['state_id' => Constants::STATE_DEACTIVATE]);
                        $groups = $createdIdea->groups;
                        if (!empty($groups)) {
                            foreach ($groups as $group) {
                                $chatNew = Chat::create([
                                    'message' => 'Left the chat group',
                                    'group_id' => $group->id,
                                    'type_id' => Constants::CHAT_TYPE_USER_LEFT,
                                    'user_id' => $auth->id
                                ]);
                                $groupUserExists = DB::table('group_user')->where('group_id', $group->id)
                                    ->where('state_id', Constants::STATE_ACTIVE)->get();
                                // update all group members, of user leaving this group due to account close
                                if (!empty($groupUserExists) && $groupUserExists->count() > 0) {
                                    foreach ($groupUserExists as $groupUser) {
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
                            }
                        }
                        // revision
                        $revision = [
                            'user_id' => $auth->id,
                            'revisionable_id' => $createdIdea->id,
                            'revisionable_type' => get_class($createdIdea),
                            'key_string' => $auth->name . " contributor of this Idea, has closed the account. ",
                            'type' => Constants::VERSION_IDEA
                        ];
                        Revision::create($revision);
                    }
                }
                // closing an account (User will get blocked):
                $this->sendClosingAccountNotification($request->account_close_notes);
                $getupdate = Idea::where('created_by',$auth->id)->whereIn('status',[ 0, 1, 3, 4])->update([
                    'status' => Constants::IDEA_CLOSED,
                    'status_title' => 'closed',
                ]);
                $auth->update([
                    'state_id' => Constants::STATE_CLOSED,
                    'account_close_notes' => $request->account_close_notes,
                    'enable_notification' => Constants::STATE_DEACTIVATE
                ]);
                broadcast(new UserBlock(Constants::STATE_REJECTED, $auth->id));
                return $this->success($msg);
            } else {
                return $this->failed('No access to perform this action');
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function closeAccountJournalist(Request $request)
    {
        $request->validate([
            'account_close_notes' => 'required'
        ]);
        $user = Auth::user();
        $msg = "Account has been closed successfully!";

        try {
            DB::beginTransaction();

            // Journalist:
            if ($user->isJournalist()) {
                $claimedIdeas = $user->journalistIdeas();
                $invitedIdeas = $user->journalistInvitedIdeas();
                if (!empty($claimedIdeas) && $claimedIdeas->count() > 0) {
                    // close active ideas.
                    $this->unclaimIdeas($claimedIdeas, $user);
                }
                if (!empty($invitedIdeas) && $invitedIdeas->count() > 0) {
                    $this->unclaimIdeas($invitedIdeas, $user);
                }
                // callouts will become inactive (will get archived)
                $callouts = $user->callouts;
                if (!empty($callouts)) {
                    foreach ($callouts as $callout) {
                        $callout->status = Constants::STATE_DEACTIVATE;
                        $callout->save();
                    }
                }
                // close funding request by current user.
                Funding::where('status', '!=', Constants::FUNDING_CLOSED)
                    ->where('user_id', $user->id)
                    ->update(['status' => Constants::FUNDING_CLOSED]);
                // close all invitations, initiated by current user, but still pending.
                Invite::where('created_by_id', $user->id)
                    ->where('state_id', 0)
                    ->update(['state_id' => Constants::INVITE_CLOSED]);

                // closing the account (User will get blocked):
                $this->sendClosingAccountNotification($request->account_close_notes);
                $user->update([
                    'state_id' => Constants::STATE_CLOSED,
                    'account_close_notes' => $request->account_close_notes,
                    'enable_notification' => Constants::STATE_DEACTIVATE
                ]);
                DB::commit();
                broadcast(new UserBlock(Constants::STATE_REJECTED, $user->id));
                return $this->success($msg);
            } else {
                return $this->failed('No access to perform this action.');
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function unclaimIdeas($claimedIdeas, $user)
    {
        foreach ($claimedIdeas as $claimedIdea) {
            $unclaim = false;
            $claimedBy = !empty($claimedIdea->claimedBy) ? $claimedIdea->claimedBy : null;
            $assignedTo = !empty($claimedIdea->assignedTo) ? $claimedIdea->assignedTo : null;
            $assignedToColloborator = null;
            $collaborator = null;
            // if journalist is the claimed by for this idea or assigned to
            if ($claimedIdea->claimed_by == $user->id || $claimedIdea->assigned_to == $user->id) {
                $invite = Invite::where('idea_id', $claimedIdea->id)
                    ->where('state_id', Constants::INVITE_ACCEPTED)
                    ->first();
                // if collaborator exists, carry forwarded by the Collaborator.
                if (!empty($invite)) {
                    $claimedIdea['assigned_to'] = null;
                    $claimedIdea['claimed_by'] = $invite->user_id;
                    $assignedToColloborator = $invite;
                } else {
                    //unclaim
                    $unclaim = true;
                }
            }
            // if journalist is collaborator for this idea
            $collaborator = $claimedIdea->isJournalistInvited(true);

            if (!empty($collaborator)) {
                $invite = Invite::where('idea_id', $claimedIdea->id)
                    ->where('user_id', $user->id)
                    ->update(['state_id' => Constants::INVITE_CLOSED]);
            }
            // delete chatrooms as well.
            $claimedIdea->groups()->each(function ($group) use ($user) {
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

            if ($unclaim) {
                $claimedIdea['status'] = Constants::IDEA_APPROVED;
                $claimedIdea['claimed_notification_sent'] = 0;
                $claimedIdea['claimed_mail_sent'] = 0;
                $claimedIdea['status_title'] = 'Approved';
                $claimedIdea['claimed_date'] = NULL;
                $claimedIdea['claimed_status'] = 0;
                $claimedIdea['claimed_by'] = null;
                $claimedIdea['assigned_to'] = null;
            }
            $claimedIdea->save();
            // revision
            $revision = [
                'user_id' => $user->id,
                'revisionable_id' => $claimedIdea->id,
                'revisionable_type' => get_class($claimedIdea),
                'key_string' => $user->name . " has closed the account. ", // contri 1 J 2
                'type' => Constants::VERSION_IDEA
            ];
            Revision::create($revision);
            (new IdeaController)->sendUnclaimNotification($claimedIdea, $claimedBy, $assignedTo, $assignedToColloborator, $collaborator, true);
        }
        return true;
    }

    public function sendClosingAccountNotification($notes)
    {
        $user = Auth::user();
        if ($user) {
            $subject = " User Account Closed ";
            $first = " " . $user->name . ' has closed account. ';
            $notes = " Closing notes: " . $notes . " ";
            $data = [];

            if ($user->isJournalist()) {
                //If a Journalist closes an account the notifications would go to:
                //Media House Admin
                $mediaHouse = $user->myMediaHouse(true);
                if (!empty($mediaHouse)) {
                    $this->customSendMail($mediaHouse, (new IdeaMail(
                        $subject,
                        $subject,
                        "Hello",
                        $first,
                        $notes,
                        "",
                        ""
                    )));
                    $data['user_id'][] = $mediaHouse->id;
                }
            }

            if ($user->isCommUser()) {
                // If a Contributor closes an account the notifications would go to:
                //     - Contributor
                //     - Journalists who were working on ideas (which are in progress)
                //     - Followers of the idea
                $createdIdeas = $user->ideas;
                if (!empty($createdIdeas) && $createdIdeas->count() > 0) {
                    foreach ($createdIdeas as $createdIdea) {
                        $byContributor = [];
                        $first = " Contributor is closing account, of idea: " . $createdIdea->title . " ";
                        $url = Constants::FRONTEND_URL . "/ideas/" . $createdIdea->slug;

                        // to journalist working on the idea
                        if (!empty($createdIdea->claimedBy)) {
                            $this->customSendMail($createdIdea->claimedBy, (new IdeaMail(
                                $subject,
                                $first,
                                "Hello",
                                $first,
                                $notes,
                                $url,
                                " VIEW IDEA "
                            )));
                            $byContributor['user_id'][] = $createdIdea->claimedBy->id;
                        }
                        // to followers
                        if (!empty($createdIdea->follows) && $createdIdea->follows->count() > 0) {
                            foreach ($createdIdea->follows as $follows) {
                                if (!empty($follows->user)) {
                                    $this->customSendMail($follows->user, (new IdeaMail(
                                        $subject,
                                        $first,
                                        "Hello",
                                        $first,
                                        $notes,
                                        $url,
                                        " VIEW IDEA "
                                    )));
                                    $byContributor['user_id'][] = $follows->user->id;
                                }
                            }
                        }
                        $byContributor['created_by_id'] = $user->id;
                        $byContributor['notifiable_id'] = $user->id;
                        $byContributor['notifiable_type'] = get_class($user);
                        $byContributor['message'] =  $first;
                        $byContributor['type_id'] = Constants::NOTIFICATION_USER_ACCOUNT_CLOSE;
                        Helper::sendNotification($byContributor);
                    }
                }
            }

            $admins = User::AdminData();
            if ($admins) {
                foreach ($admins as $admin) {
                    // mail to admins
                    $this->customSendMail($admin, (new IdeaMail(
                        $subject,
                        $subject,
                        "Hello",
                        $first,
                        $notes,
                        "",
                        ""
                    )));

                    $data['user_id'][] = $admin->id;
                }
            }
            $data['notifiable_id'] = $user->id;
            $data['notifiable_type'] = get_class($user);
            $data['created_by_id'] = $user->id;
            $data['is_alert'] = true;
            $data['message'] = $first;
            $data['type_id'] = Constants::NOTIFICATION_USER_ACCOUNT_CLOSE;
            Helper::sendNotification($data);
        }
        return true;
    }

    public function uploadImage(Request $request)
    {
        try {
            $profile_img_path = Helper::saveImage($request->image, 'page-details-images', 1);
            return $this->success([
            // 'data' => 'https://cportals.appsndevs.com' . $profile_img_path
                'data' => 'https://cportals.appsndevs.com/storage' . $profile_img_path
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
        
    }
}
