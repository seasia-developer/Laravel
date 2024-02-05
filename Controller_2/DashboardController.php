<?php

namespace App\Http\Controllers;

use App\Chat;
use App\Idea;
use App\User;
use App\Constant\Constants;
use App\Invite;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Mockery\Matcher\Contains;

class DashboardController extends Controller
{

    public function admin()
    {
        try {
            $start = Carbon::now()->subDays(30)->startOfDay();
            $today = Carbon::now()->startOfDay();

            $data = [];

            $data['messagesCount'] = User::messagesAdmin();
            $data['pendingIdeasCount'] = Idea::where("status", Constants::IDEA_PENDING)->count();
            // ->whereDate('created_at', '>', $start)->count();
            $data['pendingIdeasCountAll'] = Idea::where("status", Constants::IDEA_PENDING)->count();
            $data['rejectedIdeasCount'] = Idea::where("status", Constants::IDEA_REJECTED)->count();
            $data['claimedIdeasCount'] = Idea::where("status", Constants::IDEA_CLAIMED)->count();
            $data['mergedIdeasCount'] = Idea::where("is_merged", Constants::IS_MERGED)->count();
            $data['totalIdeasCount'] = Idea::where('status', '!=', Constants::IDEA_INACTIVE)->whereDate('created_at', '>', $start)->count();
            $data['unclaimedIdeasCount'] = Idea::where("status", Constants::IDEA_APPROVED)->count();
            $data['unclaimedIdeasCountAll'] = Idea::whereIn(
                'status',
                [
                    Constants::IDEA_APPROVED,
                    Constants::IDEA_PUBLISHED,
                    Constants::IDEA_CLAIMED
                ]
            )->count();
            $data['publishedIdeasCount'] = Idea::where("status", Constants::IDEA_PUBLISHED)->count();
            $data['newUsersCount'] = User::where('role_id', '!=', Constants::TYPE_ADMIN)
                ->where('role_id', '!=', Constants::TYPE_SUB_ADMIN)
                ->where('role_id', '!=', Constants::TYPE_SUPER_ADMIN)
                ->whereDate('created_at', '>', $start)->count();
            $data['totalUsersCount'] = User::where('role_id', '!=', Constants::TYPE_ADMIN)
                ->where('role_id', '!=', Constants::TYPE_SUB_ADMIN)->where('role_id', '!=', Constants::TYPE_SUPER_ADMIN)->count(); // according to doc confused
            $data['editorsCount'] = User::where('role_id', Constants::TYPE_SUB_ADMIN)->count();
            $data['commUsersCount'] = User::where('role_id', Constants::TYPE_COMMUNITY_USER)->count();
            $data['commUsersCountActive'] = User::where('role_id', Constants::TYPE_COMMUNITY_USER)->count();
            // ->where('state_id', Constants::STATE_ACTIVE)->count();

            $data['mediaHouseCount'] = User::where('role_id', Constants::TYPE_MEDIA_HOUSE)->count();
            $data['journalistCount'] = User::where('role_id', Constants::TYPE_JOURNALIST)->count();
            // ->where('state_id', Constants::STATE_ACTIVE)->count();
            $data['journalistPendingCount'] = User::where('role_id', Constants::TYPE_JOURNALIST)->where('email_verified_at', '!=', NULL)->where('state_id', Constants::STATE_DEACTIVATE)->count();
            // ->whereDate('created_at', '>', $start)->count();
            $data['todayIdeasCount'] = Idea::where('status', '!=', Constants::IDEA_INACTIVE)->where('created_at', '>', $today)->count();
            $data['ideaSubmittedList'] = Idea::where("status", Constants::IDEA_PENDING)->with('createdBy:id,name,profile_image')
                ->orderBy('id', 'DESC')
                ->limit(5)
                ->get();
            $data['ideaClaimedList'] = Idea::where("status", Constants::IDEA_CLAIMED)->with('claimedBy:id,name,profile_image')
                ->orderBy('id', 'DESC')
                ->limit(5)
                ->get();

            return $this->success([
                'data' => $data,
                'message' => 'data fetched successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }


    }

    public function journalist()
    {
        try {
            $user = Auth::user();
            $data = [];
            $data['ideas_unclaimed'] =  Idea::where("status", Constants::IDEA_APPROVED)->count();
            // $data['ideas_unclaimed'] = Idea::whereIn(
            //     'status',
            //     [
            //         Constants::IDEA_APPROVED,
            //         Constants::IDEA_PUBLISHED,
            //         Constants::IDEA_CLAIMED
            //     ]
            // )->count();
            $data['ideas_published'] =  Idea::where("status", Constants::IDEA_PUBLISHED)->count();
            $data['journalistCount'] = User::where('role_id', Constants::TYPE_JOURNALIST)->count();
            // ->where('state_id', Constants::STATE_ACTIVE)->count();
            $data['commUsersCount'] = User::where('role_id', Constants::TYPE_COMMUNITY_USER)->count();
            // ->where('state_id', Constants::STATE_ACTIVE)->count();
            // get invite accepted ideas.
            $inviteIdeas = Invite::select('idea_id')
                ->where('user_id', $user->id)
                ->where('state_id', Constants::INVITE_ACCEPTED)
                ->pluck('idea_id')
                ->toArray();

            $ideas_in_progress =  Idea::where("claimed_by", $user->id)->where('status', Constants::IDEA_CLAIMED);
            // ->where('claimed_status', '!=', Constants::CLAIMED_IDEA_STATUS_PUBLISH)
            if (!empty($inviteIdeas)) {
                $ideas_in_progress = $ideas_in_progress->orWhereIn('id', $inviteIdeas)->where('status', Constants::IDEA_CLAIMED);
            }
            $data['ideas_in_progress'] =     $ideas_in_progress->count();
            $data['ideas_publish'] = Idea::where("claimed_by", $user->id)->where('status', Constants::IDEA_PUBLISHED)->count();
            $data['new_messages'] = $user->messages();
            $data['new_invitations'] = Invite::where("user_id", $user->id)->where("state_id", 0)->count();
            return $this->success([
                'data' => $data,
                'message' => 'data fetched successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }

        
    }
    public function communityUser()
    {
        try {
            $user = Auth::user();
            $data = [];
            $data['ideas_submitted'] =  Idea::where("created_by", $user->id)->count();
            $approved =  Idea::where(["created_by" => $user->id, 'status' => Constants::IDEA_APPROVED])->count();
            $data['ideas_published'] =  Idea::where(["created_by" =>  $user->id, 'status' => Constants::IDEA_PUBLISHED])->count();
            $data['ideas_published_all'] =  Idea::where('status', Constants::IDEA_PUBLISHED)->count();
            $data['ideas_claimed'] =  Idea::where(["created_by" =>  $user, 'status' => Constants::IDEA_CLAIMED])->count();
            $data['ideas_pending'] =  Idea::where(["created_by" => $user, 'status' => Constants::IDEA_PENDING])->count();
            $data['follow_up_received'] = 0;
            $data['new_messages'] = $user->messages();
            $data['ideas_approved'] = ($approved + $data['ideas_published'] + $data['ideas_claimed']);
            // $data['ideas_approved_all'] = Idea::where("status", Constants::IDEA_APPROVED)->count();
            $data['ideas_approved_all'] =  Idea::whereIn(
                'status',
                [
                    Constants::IDEA_APPROVED,
                    Constants::IDEA_PUBLISHED,
                    Constants::IDEA_CLAIMED
                ]
            )->count();
            $data['journalistCount'] = User::where('role_id', Constants::TYPE_JOURNALIST)->count();
            // ->where('state_id', Constants::STATE_ACTIVE)->count();
            $data['commUsersCount'] = User::where('role_id', Constants::TYPE_COMMUNITY_USER)->count();
            // ->where('state_id', Constants::STATE_ACTIVE)->count();

            return $this->success([
                'data' => $data,
                'message' => 'data fetched successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }

        
    }

    public function mediaHouse()
    {
        try {

        $data = [];
        $user = Auth::user();
        $journalistIds = $user->mediaHouseJournalists();
        $data['journalists'] = count($journalistIds);
        array_push($journalistIds, $user->id);
        $data['ideas_published'] =  Idea::whereIn("claimed_by", $journalistIds)->where(['status' => Constants::IDEA_PUBLISHED])->count();
        $data['ideas_claimed'] =  Idea::whereIn("claimed_by",  $journalistIds)->where(['status' => Constants::IDEA_CLAIMED])->count();
        $data['ideas_in_progress'] =  Idea::where("claimed_by", $user->id)->where('status', '5')->where(function ($query) {
                     $query->where('claimed_status', '=', '3')
                     ->orWhere('claimed_status', '=', '1');
                      })
            // ->where('claimed_status', '!=', Constants::CLAIMED_IDEA_STATUS_PUBLISH)
            ->count();
        $data['no_of_approved_ideas'] = Idea::whereIn(
            'status',
            [
                Constants::IDEA_APPROVED,
                Constants::IDEA_PUBLISHED,
                Constants::IDEA_CLAIMED
            ]
        )->count();
        $data['ideas_published_all'] =  Idea::where("status", Constants::IDEA_PUBLISHED)->count();
        $data['participating_journalist'] = User::where('role_id', Constants::TYPE_JOURNALIST)->count();
        // ->where('state_id', Constants::STATE_ACTIVE)->count();
        $data['community_contributors_all'] = User::where('role_id', Constants::TYPE_COMMUNITY_USER)->count();

        return $this->success([
            'data' => $data,
            'message' => 'data fetched successfully'
        ]);
     }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function homepageCount()
    {
        try {

            $data= [];
            $data['ideas_submitted'] =  Idea::whereIn(
                'status',
                [
                    Constants::IDEA_APPROVED,
                    Constants::IDEA_PUBLISHED,
                    Constants::IDEA_CLAIMED
                ]
            )->count();
            $data['ideas_published'] =  Idea::where('status', Constants::IDEA_PUBLISHED)->count();
            $data['journalistCount'] = User::where('role_id', Constants::TYPE_JOURNALIST)->count();
            $data['commUsersCount'] = User::where('role_id', Constants::TYPE_COMMUNITY_USER)->count();
            return $this->success([
                'data' => $data,
                'message' => 'data fetched successfully'
            ]);

        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }

        
    }
}
