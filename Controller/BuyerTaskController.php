<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Task;
use Illuminate\Support\Facades\Auth;
use App\Models\Agents;
use Illuminate\Support\Facades\Mail;
use App\Mail\BuyerTaskMail;
use App\Models\Buyers;

class BuyerTaskController extends Controller
{
    public function index(Request $request)
    {
        try {
            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $query = Task::select('id', 'agent_id', 'subject', 'startdate', 'tasktype', 'buyer_id')->where('active', '1');

            $user = Auth::user();

            if($user->hasRole('Agent')) {
                $query->where('agent_id', auth_agent_id());
            }

            if(!empty($request->agent) && $request->agent != 'All') {
                $query->where('agent_id', $request->agent);
            }

            $query->with(['agent' => function($agent){
                $agent->select('id', 'firstname', 'lastname');
            }]);

            $query->with(['buyer' => function($buyer){
                $buyer->select('id', 'firstname', 'lastname');
            }]);

            $tasks = $query->orderBy('id', 'DESC')->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$tasks]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function store(Request $request, $buyer_id)
    {
        try {

            $input = $request->only(['agent_id', 'startdate', 'enddate', 'notes', 'action', 'reminder', 'subject', 'tasktype', 'txt']);

            $validator = Validator::make($input, [
                'agent_id' => 'required',
                'startdate' => 'required',
                'enddate' => 'required',
                'notes' => 'required',
                'action' => 'required',
                'reminder' => 'required',
                'subject' => 'required',
            ]);
     
            if ($validator->fails()) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
            }

            $input['uid'] = Auth::user()->id;
            $input['utype'] = Auth::user()->type;
            $input['buyer_id'] = $buyer_id;

            if(isset($input['tasktype']) == 'g') {
                $input['tasktype'] = 'g';
            } 
            
            if(isset($input['tasktype']) == 'b') {
                $input['tasktype'] = 'b';

                $buyer = Buyers::select('firstname', 'lastname')->find($buyer_id);

                $input['txt'] = $buyer->firstname.' '.$buyer->lastname;

            }

            $model = new Task();
            $prefix = 'task';
            $delete_from_redis = null;
            $task = redisCreate($model, $input, $prefix, $delete_from_redis);

            if($request->sendnow) {
                $agent = Agents::select('id','email')->find($input['agent_id']);

                $mailData = [
                    'subject_name' => $input['subject'],
                    'text_body' => $input['notes'],
                ];

                if(validEmail($agent->email)){
                    Mail::to($agent->email)->queue(new BuyerTaskMail($mailData));
                }
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>$task]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function done(Request $request, $id)
    {
        try {
            $input['active'] = 0;
            $input['reminder'] = 'None';
            $input['emlonstart'] = 'N';
            $input['emlonend'] = 'N';

            $model = new Task();
            $prefix = 'task';
            $delete_from_redis = null;

            $task = redisUpdate($model, $input, $id, $prefix, $delete_from_redis);	

            return response()->json(['message'=>'success','code'=>'200','data'=>$task]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }
}
