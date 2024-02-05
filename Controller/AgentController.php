<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Agents;
use App\Models\User;
use App\Models\Royal;
use App\Models\ActivityLogs;
use App\Models\AgentsDocument;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;


class AgentController extends Controller
{

    public function index(Request $request)
    {
        try {
            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $query = Agents::select('id', 'firstname', 'lastname', 'username', 'zipcode', 'status', 'placement', 'isTypeAO');

            if($request->filled('agents_and_offices')) {
                if($request->agents_and_offices == 'Active Agents') {
                    $query->where('isTypeAO', 'A')->where('status', '1');
                }

                if($request->agents_and_offices == 'Inactive Agents') {
                    $query->where('isTypeAO', 'A')->where('status', '0');
                }

                if($request->agents_and_offices == 'Active Offices') {
                    $query->where('isTypeAO', 'O')->where('status', '1');
                }

                if($request->agents_and_offices == 'Inactive Offices') {
                    $query->where('isTypeAO', 'O')->where('status', '0');
                }
            }

            $agents = $query->orderBy('id', 'DESC')->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$agents]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function store(Request $request) {
        try {

            $id = isset($request->id)?$request->id:null;

            if (isset($id) && isset($request->password) && empty($request->password)) {
                $input = $request->except(['id', 'royal', 'royality', 'marketing', 'password']);
            }
            else {
                $input = $request->except(['id', 'royal', 'royality', 'marketing']);
            }

            $rules = [
                'title' => 'required',
                'firstname' => 'required',
                'lastname' => 'required',
                'username' => 'required',
            ];
        
            if(!isset($id)) {
                $rules = array_merge($rules, ['password' => 'required|min:8|string']);
                $rules = array_merge($rules, ['email' => 'required|email:rfc,dns|unique:agents|unique:users']);
            } else {
                $rules = array_merge($rules, [
                    'email' => [
                        'required', 'email:rfc,dns',
                        Rule::unique('agents')->ignore($id),
                    ]
                ]);
                if(isset($request->password) && !empty($request->password)){
                    $rules = array_merge($rules, ['password' => 'required|min:8|string']);
                }
            }

            if($request->hasFile('img')){  
                $rules = array_merge($rules, ['img' => 'image|nullable|max:5120']);
            }

            if($request->hasFile('map_img')){  
                $rules = array_merge($rules, ['img' => 'image|nullable|max:5120']);
            }
            
            $validator = Validator::make($input, $rules);
     
            if ($validator->fails()) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
            }

            if($img_file = $request->file('img')){
                $filenameWithExt = $img_file->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $img_file->getClientOriginalExtension();
                $input['img'] = $filename.'_'.time().'.'.$extension;
                $img_file->move('storage/images/users', $input['img']);
                $input_2['img'] = $input['img'];
            }

            if($img_file_2 = $request->file('map_img')){
                $filenameWithExt_2 = $img_file_2->getClientOriginalName();
                $filename_2 = pathinfo($filenameWithExt_2, PATHINFO_FILENAME);
                $extension_2 = $img_file_2->getClientOriginalExtension();
                $input['map_img'] = $filename_2.'_'.time().'.'.$extension_2;
                $img_file_2->move('storage/images/map-images', $input['map_img']);
            }

            if(isset($request->password) && !empty($request->password)){
                $input['password'] = Hash::make($input['password']);
                $input_2['password'] = $input['password'];
            }
            
            //user table
            $input_2['email'] = $input['email'];
            $input_2['firstname'] = $input['firstname'];
            $input_2['lastname'] = $input['lastname'];
            $input_2['username'] = $input['username'];
            
            if($input['agent_level'] == '5') {
                $input_2['type'] = '5';
            }

            if($input['agent_level'] == '6') {
                $input_2['type'] = '6';
            }

            $agent_find = Agents::find($id);

            if(isset($agent_find)){
                $agent_user_id = $agent_find->user_id;
            } else {
                $agent_user_id = null;
            }
            
            $model_2 = new User();
            $prefix_2 = 'user';
            $delete_from_redis_2 = null;
            $select_column = null;

            $input_match_2 = ['id'   => $agent_user_id];
            $user = redisUpdateOrCreate($model_2, $input_match_2, $input_2, $prefix_2, $delete_from_redis_2);

            if($user->type == '5') {
                $user->assignRole(['Agent']);
            }

            if($user->type == '6') {
                $user->assignRole(['Agent Manager']);
            }
            //close user table

            $input['user_id'] = $user->id;

            $model = new Agents();
            $prefix = 'agent';
            $delete_from_redis = null;
            $input_match = ['id'   => $id];
            $agent = redisUpdateOrCreate($model, $input_match, $input, $prefix, $delete_from_redis);

            if($agent->isTypeAO == 'O'){
                Royal::where('office_id', $agent->id)->delete();

                if($request->filled('royal')) {
                    foreach ($request->royal as $key => $value) {
                        $value['office_id'] = $agent->id;
                        $value['royality'] = $request->royality;
                        $value['marketing'] = $request->marketing;
                        Royal::create($value);
                    }
                }
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>$agent]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function show($id)
    {
        try {
            $model = new Agents();
            $prefix = 'agent';
            $select_column = null;

            $agent = redisFind($model, $select_column, $id, $prefix);

            if($agent->isTypeAO == 'O'){
                $agent->office_royal = Royal::where('office_id', $agent->id)->get();
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>$agent]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function update_status(Request $request, $id)
    {
        try {
            $input = $request->only(['status']);

            $validator = Validator::make($input, [
                'status' => 'required',
            ]);
     
            if ($validator->fails()) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
            }

            $model = new Agents();
            $prefix = 'agent';
            $delete_from_redis = 'office_agents';
            Redis::del('office_franchise');
            
            $agent = redisUpdate($model, $input, $id, $prefix, $delete_from_redis);

            $model_2 = new User();
            $prefix_2 = 'user';
            $delete_from_redis_2 = null;
            $user = redisUpdate($model_2, $input, $agent->user_id, $prefix_2, $delete_from_redis_2);
            
            $type = 'Agent';

            $input_2 = [
                'agent_id' => $agent->id,
                'username' => $user->username,
                'status' => $user->status,
                'type' => $type 
            ];

            $model_3 = new ActivityLogs();
            $prefix_3 = 'activity_log_agent';
            $delete_from_redis_3 = null;
            $activity_log = redisCreate($model_3, $input_2, $prefix_3, $delete_from_redis_3);

            return response()->json(['message'=>'success','code'=>'200','data'=>$agent]);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }

    }

    public function activity_logs(Request $request) {
        try {
            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $query = ActivityLogs::select('id','agent_id', 'username', 'status', 'created_at')->whereNotNull('agent_id');

            if(isset($request->search) && !empty($request->search)){
                $searchFields = ['username', 'status'];
                $query->where(function($query) use($request, $searchFields){
                  $searchWildcard = '%' . $request->search . '%';
                  foreach($searchFields as $field){
                    $query->orWhere($field, 'LIKE', $searchWildcard);
                  }
                });
            }

            $activity_logs = $query->latest()->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$activity_logs]);
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function placement(Request $request, $id)
    {
        try {
                $validator = Validator::make($request->all(), [
                    'placement' => 'required',
                ]);
         
                if ($validator->fails()) {
                    return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
                }

                $agents  = Agents::where('id', $id)->update([
                    'placement' => $request->placement
                ]);

                return response()->json(['message'=>'success','code'=>'200','data'=>'Display Order Updated Successfully']);

            } catch (\Exception $e) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
            }
    }

    public function documents_upload(Request $request, $id)
    {
        try {
            $request->validate([
                'agent_document' => 'required',
                'agent_document.*' => 'mimes:pdf,xlsx,xls,csv,jpg,png,gif|max:20480',
            ]);
          
            $files = [];
            if ($request->file('agent_document')){
                foreach($request->file('agent_document') as $key => $file)
                {
                    $fileSize[] = $file->getSize();
                    $filenameWithExt = $file->getClientOriginalName();
                    $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                    $extension = $file->getClientOriginalExtension();
                    $new_filename = $filename.'_'.time();
                    $new_filenameWithExtension = $new_filename.'.'.$extension;
                    $file->move('storage/agent/docs', $new_filenameWithExtension);
                    $files[]['nameWithExtension'] = $new_filenameWithExtension;
                }
            }

            $input = $request->except(['agent_document']);

            foreach ($files as $key => $file) {
                $input['agent_document'] = $file['nameWithExtension'];
                $input['agentid'] = $id;
                $input['user_id'] = Auth::id();
                $input['user_type'] = Auth::user()->type;

                AgentsDocument::create($input);
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>'You have successfully upload file.']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }

    }

    public function document_list(Request $request, $id)
    {
        try {
            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $docs = AgentsDocument::where('agentid', $id)->
            with(['user' => function($user){
                $user->select('id', 'username', 'firstname', 'lastname');
            }])->orderBy('id', 'DESC')->paginate($per_page);


            return response()->json(['message'=>'success','code'=>'200','data'=>$docs]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function delete_document($id)
    {
        try {
            AgentsDocument::findOrFail($id)->delete();
            
            return response()->json(['message'=>'success','code'=>'200','data'=>'Document deleted successfully']);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function all_agents_and_filter(Request $request){
        try {
            $query = Agents::select('id', 'firstname', 'lastname', 'username', 'email', 'agent_level', 'franchiseofficeid')->where('status', '1')->where('isTypeAO', 'A');

            if($request->filled('same_franchise_agent')) {

                $agent = Agents::select('franchiseofficeid')->find($request->same_franchise_agent);

                $franchises = explode(',', $agent->franchiseofficeid);

                $query->where(function($franchise_query) use($franchises) {
                    foreach($franchises as $franchise) {
                        $franchise_query->orWhereRaw('FIND_IN_SET("'.$franchise.'",franchiseofficeid)');
                    };
                });
            }

            if($request->filled('agent_level')) {
                $query->where('agent_level', $request->agent_level);
            }

            $agents = $query->orderBy('id', 'DESC')->get();
            
            return response()->json(['message'=>'success','code'=>'200','data'=>$agents]);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }                                                         
}
