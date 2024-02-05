<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;
use App\Models\Buyers;
use App\Models\CaBuyers;
use App\Models\Listing;
use App\Models\Ca;
use App\Models\BuyerUserDocument;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\BuyerDocumentMail;
use App\Mail\BuyerBuildAdvertisingMail;
use App\Models\BuyerUserHot;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ExportBuyerNameEmail;
use App\Exports\ExportBuyerNotes;
use App\Exports\ExportHotReport;
use App\Models\ExcelExportReport;
use Illuminate\Support\Str;
use App\Models\BuyerUserNotes;
use App\Exports\LoiHotReportExport;
use App\Exports\InContractHotReportExport;
use App\Exports\HotBuyersHotReportExport;
use App\Exports\SignedCaHotReportExport;
use App\Exports\NewBuyersHotReportExport;
use App\Exports\ComingSoonHotReportExport;
use App\Exports\FranchiseLeadsHotReportExport;
use App\Exports\AppointmentLeadsHotReportExport;
use Illuminate\Support\Facades\Storage;

class BuyerController extends Controller
{
    
    public function all(Request $request)
    {
        try {
            $model = new Buyers(); 
            $prefix = 'buyers';
            $select_column = ['id', 'firstname', 'lastname', 'email', 'phoneno', 'buyerlegalname'];

            $buyers = redisGetAllRows($model, $select_column, $prefix);

            return response()->json(['message'=>'success','code'=>'200','data'=>$buyers]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function index(Request $request)
    {
        try {
            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $query = Buyers::select('id', 'firstname', 'lastname', 'email', 'phoneno', 'willlistingno', 'agentid', 'created_at')->
                with(['agent' => function($agent){
                    $agent->select('id', 'firstname', 'lastname');
                }])->
                with(['listing' => function($listing){
                    $listing->select('id', 'bname', 'bstatuslist', 'bamount', 'olagent', 'buyer_email')->whereIn('bstatuslist', ['Available', 'Coming Soon', 'LOI', 'In Contract']);
                }])->
                with(['hot' => function($hot){
                    $hot->select('id', 'buyer_id', 'agent_id');
                }])->
                with(['ca' => function($ca){
                    $ca->select('id', 'buyer_id', 'listing_id', 'agentid', 'nosigned', 'is_hot');
                }]);

            $user = Auth::user();

            if($user->hasRole('Agent')) {
                $query->where('agentid', auth_agent_id());
            }

            if(!empty($request->agent) && $request->agent != 'All') {
                $query->where('agentid', $request->agent);
            }

            if($request->filled('stage')) {

                if($request->stage == 'Hot Buyers') {
                    $query->whereHas('hot');
                }

                if($request->stage == 'Signed CAs') {
                    $query->whereHas('ca', function ($ca_filter)  use ($request){
                        $ca_filter->where('nosigned', '>', '0')->where('is_hot', 'Y');
                    });
                }

            }

            if($request->filled('email')) {
                $query->where('email', $request->email);
            }

            if($request->filled('firstname')) {
                $query->where('firstname', $request->firstname);
            }

            if($request->filled('lastname')) {
                $query->where('lastname', $request->lastname);
            }

            if($request->filled('phoneno')) {
                $query->where('phoneno', $request->phoneno);
            }

            $buyers = $query->orderBy('id', 'DESC')->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$buyers]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function store(Request $request)
    {
        try {
            $input = $request->all();

            $validator = Validator::make($input, [
                'firstname' => 'required',
                'lastname' => 'required',
                'email' => 'required|email:rfc,dns|unique:buyer_users|unique:users',
                'agentid' => 'required',
                'lookingstates' => 'required',
                'willlistingno' => 'required',
            ]);
     
            if ($validator->fails()) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
            }

            if (User::where('email', $input['email'])->exists()) {
                $user = User::where('email', '=', $input['email'])->first();
            } else {
                // Create User for Login
                $input_2['email'] = $input['email'];
                $input_2['password'] = Hash::make(Str::random(8));
                $input_2['username'] = $input['firstname'];
                $input_2['firstname'] = $input['firstname'];
                $input_2['lastname'] = $input['lastname'];
                $input_2['type'] = '4';
                $model_2 = new User();
                $prefix_2 = 'user';
                $delete_from_redis_2 = null;
                $user = redisCreate($model_2, $input_2, $prefix_2, $delete_from_redis_2);
                $user->assignRole(['Buyer']);
            }

            $input['user_id'] = $user->id;

            if(!empty($input['willlistingno'])){
                $willlistingno = $input['willlistingno'];
                $willlistingno_array = explode(',', $willlistingno);
                $willlistingno_array_trimmed = array_map('trim', $willlistingno_array);

                foreach ($willlistingno_array_trimmed as $key => $listingno) {
                    $input['willlistingno'] = $listingno;
                    $model = new Buyers();
                    $prefix = 'buyer';
                    $delete_from_redis = 'buyers';
                    $buyer[$key] = redisCreate($model, $input, $prefix, $delete_from_redis);
                }
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>$buyer]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function show($id)
    {
        try {
            $model = new Buyers();
            $prefix = 'buyer';
            $select_column = null;

            $buyer = redisFind($model, $select_column, $id, $prefix);

            return response()->json(['message'=>'success','code'=>'200','data'=>$buyer]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }


    public function update(Request $request, $id)
    {
        try {
            if (isset($request->password) && empty($request->password)) {
                $input = $request->except(['password']);
            }
            else {
                $input = $request->all();
                $input['password'] = Hash::make($input['password']);
            }   

            $buyer = Buyers::select('id', 'user_id')->find($id);

            $rules = [
                'firstname' => 'required',
                'lastname' => 'required',
                'email' => [
                    'required', 'email:rfc,dns',
                    Rule::unique('buyer_users')->ignore($id),
                ],
                'lookingstates' => 'required',
                'staddress' => 'required',
                'city' => 'required',
                'postalcode' => 'required',
                'phoneno' => 'required',
                'cellno' => 'required',
            ];
        
            if(isset($request->password) && !empty($request->password)){
                $rules = array_merge($rules, ['password' => 'required|min:8|string']);
            }

            if (isset($request->email) && !empty($request->email)) {
                $rules = array_merge($rules, 
                    [
                        'email' => [
                            'required', 'email:rfc,dns',
                            Rule::unique('users')->ignore($buyer->user_id),
                        ],
                    ]
                );
            }
            
            $validator = Validator::make($input, $rules);
     
            if ($validator->fails()) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
            }

            // user table
            if(isset($request->password) && !empty($request->password)){
                $input_2['password'] = $input['password'];
            }
            $input_2['email'] = $input['email'];
            $input_2['type'] = '4';
            $input_2['username'] = $input['firstname'];
            $input_2['firstname'] = $input['firstname'];
            $input_2['lastname'] = $input['lastname'];

            if(isset($buyer)){
                $buyer_user_id = $buyer->user_id;
            } else {
                $buyer_user_id = null;
            }
            $model_2 = new User();
            $prefix_2 = 'user';
            $delete_from_redis_2 = null;
            $input_match_2 = ['id'   => $buyer_user_id];
            $user = redisUpdateOrCreate($model_2, $input_match_2, $input_2, $prefix_2, $delete_from_redis_2);
            $user->assignRole(['Buyer']);

            if(isset($buyer)){
                $buyer_list = Buyers::where('user_id', $buyer->user_id)->get();

                foreach ($buyer_list as $get_buyer) {
                    $model = new Buyers();
                    $prefix = 'buyer';
                    $delete_from_redis = 'buyers';
                    $buyer_update = redisUpdate($model, $input, $get_buyer->id, $prefix, $delete_from_redis);

                }
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>'Buyer Updated Successfully']);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }

    }


    public function delete(Request $request)
    {
        try {
            $id = $request->id;
            if(!empty($id)){
                $id_array = explode(',', $id);
                foreach ($id_array as $buyer_id) {
                    $model = new Buyers();
                    $prefix = 'buyer';
                    $delete_from_redis = 'buyers';
                    $buyer = redisDelete($model, $buyer_id, $prefix, $delete_from_redis);
                }
            }
            return response()->json(['message'=>'success','code'=>'200','data'=>'Buyer deleted successfully']);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    //CA's Submitted tab

    public function ca_submitted(Request $request, $id)
    {
        try {
            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $query = CaBuyers::select('id', 'buyer_id', 'listing_id', 'lastviewdate', 'nosigned')->where('buyer_id', $id)
            ->with(['listing' => function($listing) {
                $listing->select('id', 'bname');
            }]);

            $ca_submissions = $query->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$ca_submissions]);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }


    public function ca_print(Request $request, $id, $listing_id)
    {
        try {
            $listing = Listing::select('bstate', 'burldes')->find($listing_id);
            $buyer = Buyers::select('firstname', 'lastname', 'email')->find($id);
            
            if(isset($listing->bstate)) {
                $ca =  Ca::select('catext')->where('code', $listing->bstate)->first();
            }
            
            $ca_buyer = CaBuyers::select('lastviewdate')->where('buyer_id', $id)->where('listing_id', $listing_id)->orderBy('lastviewdate', 'DESC')->first();
            $ca_print['listing_id'] = $listing_id;
            $ca_print['date_time'] = isset($ca_buyer->lastviewdate)?$ca_buyer->lastviewdate:'';
            $buyer_firstname = isset($buyer->firstname)?$buyer->firstname:'';
            $buyer_lastname = isset($buyer->lastname)?$buyer->lastname:'';
            $ca_print['your_full_name'] = $buyer_firstname.' '.$buyer_lastname;
            $ca_print['buyer_email'] = isset($buyer->email)?$buyer->email:'';
            $ca_print['description'] = isset($listing->burldes)?$listing->burldes:'';
            $ca_print['title'] = 'BUYER CONFIDENTIALITY AGREEMENT';
            $ca_print['text'] = isset($ca->catext)?$ca->catext:'';

            return response()->json(['message'=>'success','code'=>'200','data'=> $ca_print ]);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    //End CA's Submitted tab

    //Documents tab

    public function documents_upload(Request $request, $id)
    {
        try {
            $request->validate([
                'doc_file' => 'required',
                'doc_file.*' => 'mimes:pdf,xlsx,xls,doc,docx,csv,ppt,pptx,ods,odt,odp,jpg,png,gif|max:20480',
            ]);
          
            $files = [];
            if ($request->file('doc_file')){
                foreach($request->file('doc_file') as $key => $file)
                {
                    $fileSize[] = $file->getSize();
                    $filenameWithExt = $file->getClientOriginalName();
                    $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                    $extension = $file->getClientOriginalExtension();
                    $new_filename = $filename.'_'.time();
                    $new_filenameWithExtension = $new_filename.'.'.$extension;
                    $file->move('storage/buyer/docs', $new_filenameWithExtension);
                    $files[]['nameWithExtension'] = $new_filenameWithExtension;
                }
            }

            $input = $request->except(['doc_file']);

            foreach ($files as $key => $file) {
                $input['doc_file'] = $file['nameWithExtension'];
                $input['doc_title'] = preg_replace('/\\.[^.\\s]{3,4}$/', '', $file['nameWithExtension']);
                $input['buyer_id'] = $id;
                $input['doc_agent'] = Auth::id();
                $input['agent_type'] = Auth::user()->type;

                BuyerUserDocument::create($input);
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

            $docs = BuyerUserDocument::with(['agent' => function($agent){
                $agent->select('id', 'username');
            }])->
            with(['apa' => function($apa){
                $apa->select('id', 'listingId');
            }])->
            with(['amendment' => function($amendment){
                $amendment->select('id', 'listing_id');
            }])->
            with(['terminate' => function($terminate){
                $terminate->select('id', 'listing_id');
            }])->
            with(['confidentiality' => function($confidentiality){
                $confidentiality->select('id', 'listing_id');
            }])->
            where('buyer_id', $id)->latest()->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$docs]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function delete_document($id)
    {
        try {
            BuyerUserDocument::findOrFail($id)->delete();
            
            return response()->json(['message'=>'success','code'=>'200','data'=>'Document deleted successfully']);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function document_email(Request $request, $id)
    {
        try {
            $request->validate([
                'email_address' => 'required',
                'email_draft' => 'required',
                'file_to_attach' => 'required',
            ]);

            $input = $request->all();

            $file_to_attach = explode(",",$input['file_to_attach']);

            $mailData = [
                'subject_name' => 'Buyer document',
                'text_body' => $input['email_draft'],
                'attach_file' => $file_to_attach,
            ];

            $recipient = $input['email_address'];

            if(validEmail($recipient)){
                Mail::to($recipient)->queue(new BuyerDocumentMail($mailData));
            }
            
            return response()->json(['message'=>'success','code'=>'200','data'=>$input]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }

    }

    //End Documents tab


    //Make-Hot tab

    public function make_hot(Request $request, $id)
    {
        try {
            $input = $request->all();

            $validator = Validator::make($input, [
                'agent_id' => 'required'
            ]);
     
            if ($validator->fails()) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
            }


            $model = new BuyerUserHot();
            $prefix = 'buyer_user_hot_'.$id.'_'.$request->agent_id;
            $delete_from_redis = 'buyer_hotlist_'.$id;

            $input_match = [
                'buyer_id'   => $id,
                'agent_id'   => $request->agent_id
            ];

            $buyer_user_hot = redisUpdateOrCreateForRetrieve($model, $input_match, $input, $prefix, $delete_from_redis);

            return response()->json(['message'=>'success','code'=>'200','data'=>$buyer_user_hot]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function make_not_hot(Request $request, $id)
    {
        try {
            $input = $request->all();

            $validator = Validator::make($input, [
                'agent_id' => 'required'
            ]);
     
            if ($validator->fails()) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
            }

            $model = new BuyerUserHot();
            $prefix = 'buyer_user_hot_'.$id.'_'.$request->agent_id;
            $delete_from_redis = 'buyer_hotlist_'.$id;

            $where = [
                ['buyer_id', '=', $id],
                ['agent_id', '=', $request->agent_id]
            ];

            $buyer_user_not_hot = redisRetrieveDelete($model, $where, $prefix, $delete_from_redis);

            return response()->json(['message'=>'success','code'=>'200','data'=>$buyer_user_not_hot]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function make_hot_show(Request $request, $id, $agent_id)
    {
        try {
            $model = new BuyerUserHot();
            $select_column = ['id', 'buyer_id', 'agent_id'];
            $prefix = 'buyer_user_hot_'.$id.'_'.$agent_id;

            $where = [
                ['buyer_id', '=', $id],
                ['agent_id', '=', $request->agent_id]
            ];

            $buyer = redisRetrieveFind($model, $select_column, $where, $prefix);

            return response()->json(['message'=>'success','code'=>'200','data'=>$buyer]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function make_hot_list($id)
    {
        try {
            $model = new BuyerUserHot();
            $prefix = 'buyer_hotlist_'.$id;
            $select_column = ['id', 'buyer_id', 'agent_id', 'created_at'];
            $where = [
                ['buyer_id', '=', $id]
            ];
            $orWhere = null;
            $hot_list = redisGetConditionRows($model, $select_column, $prefix, $where, $orWhere);

            return response()->json(['message'=>'success','code'=>'200','data'=>$hot_list]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function make_hot_list_update(Request $request, $id)
    {
        try {

            $input = $request->all();

            $agents = explode(",",$input['agent']);

            $model = new BuyerUserHot();
            $prefix = 'buyer_user_hot_'.$id.'_*';
            $delete_from_redis = 'buyer_hotlist_'.$id;

            $where = [
                ['buyer_id', '=', $id]
            ];

            $buyer_user_not_hot = redisRetrieveDelete($model, $where, $prefix, $delete_from_redis);

            foreach($agents as $agent_id){
                if(isset($agent_id) && !empty($agent_id)){
                    $prefix_2 = 'buyer_user_hot_'.$id.'_'.$agent_id;
                    $delete_from_redis_2 = null;
        
                    $input_match = [
                        'buyer_id'   => $id,
                        'agent_id'   => $agent_id
                    ];

                    $buyer_user_hot = redisUpdateOrCreateForRetrieve($model, $input_match, $input_match, $prefix_2, $delete_from_redis_2);
                }
            }
            
            return response()->json(['message'=>'success','code'=>'200','data'=>'Updated Successfully']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function make_hot_assign_agent(Request $request, $id)
    {
        try {
            $input = $request->all();
            $buyers = explode(",",$input['buyer']);
            $agent_id = $input['agent_id'];
            $model = new BuyerUserHot();
        
            foreach($buyers as $buyer) {
                if(isset($buyer) && !empty($buyer)){
                    $prefix = 'buyer_user_hot_'.$buyer.'_'.$agent_id;
                    $delete_from_redis = 'buyer_hotlist_'.$buyer;

                    $where = [
                        ['buyer_id', '=', $buyer],
                        ['agent_id', '=', $id]
                    ];
                    $buyer_user_not_hot = redisRetrieveDelete($model, $where, $prefix, $delete_from_redis);

                    $input_match = [
                        'buyer_id'   => $buyer,
                        'agent_id'   => $agent_id
                    ];
                    $buyer_user_hot = redisUpdateOrCreateForRetrieve($model, $input_match, $input_match, $prefix, $delete_from_redis);
                }
            }
            
            return response()->json(['message'=>'success','code'=>'200','data'=>'Updated Successfully']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function make_not_hot_remove_agent(Request $request)
    {
        try {
            $input = $request->all();
            $buyers = explode(",",$input['buyer']);
            $agent_id = $input['agent_id'];
            $model = new BuyerUserHot();

            foreach($buyers as $buyer) {
                if(isset($buyer) && !empty($buyer)){                   
                    $prefix = 'buyer_user_hot_'.$buyer.'_'.$agent_id;
                    $delete_from_redis = 'buyer_hotlist_'.$buyer;
                    $where = [
                        ['buyer_id', '=', $buyer],
                        ['agent_id', '=', $agent_id]
                    ];
                    $buyer_user_not_hot = redisRetrieveDelete($model, $where, $prefix, $delete_from_redis);
                }
            }
            
            return response()->json(['message'=>'success','code'=>'200','data'=>'Removed Successfully']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }
    //End Make-Hot tab


    public function name_email_export(Request $request)
    {
        try {

            $current_time = date('F-d-Y_H_i_s');

            $input = $request->all();

            // Begin excel_export_history
            $excel_export_history = ExcelExportReport::create([
                'agent_id'=> Auth::id(),
                'agent_type'=> Auth::user()->type, 
                'date_time'=> date('m-d-Y h:i:s'), 
                'agreement_id'=> null,
                'type'=> 'Buyers',
                'ipaddress'=> $_SERVER['REMOTE_ADDR'],
                'description'=> 'Buyer Users Download',
            ]);
            // End excel_export_history

            return Excel::download(new ExportBuyerNameEmail($input), 'Buyer-Users-'.$current_time.'.xlsx');

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function buyer_notes_export(Request $request)
    {
        try {
            $current_time = date('F-d-Y_H_i_s');

            $input = $request->all();

            // Begin excel_export_history
            $excel_export_history = ExcelExportReport::create([
                'agent_id'=> Auth::id(),
                'agent_type'=> Auth::user()->type, 
                'date_time'=> date('m-d-Y h:i:s'), 
                'agreement_id'=> null,
                'type'=> 'BuyerNotes',
                'ipaddress'=> $_SERVER['REMOTE_ADDR'],
                'description'=> 'Buyer Notes Download',
            ]);
            // End excel_export_history

            return Excel::download(new ExportBuyerNotes($input), 'Buyer-Notes-'.$current_time.'.xlsx');

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }


    public function hot_report_export(Request $request)
    {
        try {
            $current_time = date('F-d-Y_H_i_s');

            return Excel::download(new ExportHotReport, 'Hot-Report-'.$current_time.'.xlsx');

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function build_advertising_email(Request $request, $id) {

        try {
            $input = $request->all();

            $buyer = Buyers::select('id', 'email', 'firstname', 'lastname', 'phoneno')->find($id);
            
            $mailData = [
                'subject_name' => $input['subject'],
                'text_body' => $input['bdetailedad'],
            ];

            if(validEmail($buyer->email)){
                Mail::to($buyer->email)->queue(new BuyerBuildAdvertisingMail($mailData));
            }

            if(!empty($input['listing_id'])){
                $listing_id = trim($input['listing_id'], '"');
                $listing_id_array = explode(',', $listing_id);
                $listing_id_array_trimmed = array_map('trim', $listing_id_array);

                foreach ($listing_id_array_trimmed as $get_listing) {
                    $data['listing_id'] = $get_listing;
                    $data['buyer_id'] = $buyer->id;
                    $data['agent_id'] = Auth::id();

                    $listing = Listing::select('id', 'bname', 'olagent', 'buyer_email')->find($get_listing);
                    $data['business_name'] = $listing->bname;

                    $data['related_to'] = 'email';
                    $data['email_to_seller'] = 'N';
                    $data['email_to_agent'] = 'N';
                    $data['email_to_buyer'] = 'N';
                    $data['note_text']  = 
                    "Template Option:" . "1" . 
                    " Buyer Name: " . 
                    $buyer->firstname.' '.$buyer->lastname . 
                    "Email: ". $buyer->email . 
                    "Telephone: ". $buyer->phoneno . 
                    "Message:";

                    $model = new BuyerUserNotes();
                    $prefix = 'buyer_user_note';
                    $delete_from_redis = null;
                    $note = redisCreate($model, $data, $prefix, $delete_from_redis);

                }
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>'New member created successfully!']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }

    }


    public function find_listing_status(Request $request)
    {
        try {
            $listings = Listing::select('id', 'bname', 'bstatuslist', 'olagent', 'bregion')->whereIn('id', explode(',', $request->id))->get();
            return response()->json(['message'=>'success','code'=>'200','data'=>$listings]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function download_hot_report(Request $request)
    {
        try {
            $current_time = date('F-d-Y_H_i_s');

            $input = $request->all();

            // Get all files in a directory
            $get_all_files =   Storage::allFiles('public/hot_reports');
            // Delete Files
            Storage::delete($get_all_files);

            if($input['HotBuyers'] == 1) {
                Excel::store(new HotBuyersHotReportExport($input), 'HotBuyers.xlsx', 'hot_reports_path');
            }
           
            if($input['InContractListings'] == 1) {
                Excel::store(new InContractHotReportExport($input), 'InContractListings.xlsx', 'hot_reports_path');
            }

            if($input['LOIListings'] == 1) {
                Excel::store(new LoiHotReportExport($input), 'LOIListings.xlsx', 'hot_reports_path');
            }

            if($input['SignedCAs'] == 1) {
                Excel::store(new SignedCaHotReportExport($input), 'SignedCAs.xlsx', 'hot_reports_path');
            }

            if($input['NewBuyerstoFollow'] == 1) {
                Excel::store(new NewBuyersHotReportExport($input), 'NewBuyerstoFollow.xlsx', 'hot_reports_path');
            }

            if($input['ComingSoonListings'] == 1) {
                Excel::store(new ComingSoonHotReportExport($input), 'ComingSoonListings.xlsx', 'hot_reports_path');
            }

            if($input['FranchiseLeads'] == 1) {
                Excel::store(new FranchiseLeadsHotReportExport($input), 'FranchiseLeads.xlsx', 'hot_reports_path');
            }

            if($input['AppointmentLeads'] == 1) {
                Excel::store(new AppointmentLeadsHotReportExport($input), 'AppointmentLeads.xlsx', 'hot_reports_path');
            }

            // begin archive zip

            // Get all files in a directory
            $get_all_zip_files =   Storage::allFiles('public/hot_reports_zip');
            // Delete Files
            Storage::delete($get_all_zip_files);

            //$zip_file = 'storage/hot_reports_zip/HotReport_'.$current_time.'.zip';
            $zip_file = storage_path('app/public/hot_reports_zip/HotReport_'.$current_time.'.zip');

            $zip = new \ZipArchive();
            $zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            $path = storage_path('app/public/hot_reports');
          
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($files as $name => $file)
            {
                // We're skipping all subfolders
                if (!$file->isDir()) {
                    $filePath     = $file->getRealPath();


                    // extracting filename with substr/strlen
                    $relativePath = 'hot_reports/' . substr($filePath, strlen($path) + 1);

                    
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();
            // close archive zip

            $file_location = url('public/storage/hot_reports_zip/HotReport_'.$current_time.'.zip');

            if(Storage::disk('public')->exists('hot_reports_zip/HotReport_'.$current_time.'.zip')) { 
                return response()->json(['message'=>'success','code'=>'200','data'=>$file_location]); 
             }else { 
                return response()->json(['message'=>'error','code'=>'302','data'=>'File not found']);
             }

            //return response()->download($zip_file);


        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }


    public function remove_from_new_buyers(Request $request)
    {
        try {
            $id = $request->id;
            if(!empty($id)){
                $id_array = explode(',', $id);
                foreach ($id_array as $buyer_id) {
                    Buyers::findOrFail($buyer_id)->update(['new' => '0']);
                }
            }
            return response()->json(['message'=>'success','code'=>'200','data'=>'Buyer removed successfully']);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function remove_from_signed_ca(Request $request)
    {
        try {
            $id = $request->id;
            if(!empty($id)){
                $id_array = explode(',', $id);
                foreach ($id_array as $buyer_id) {
                    CaBuyers::where('buyer_id', $buyer_id)->update(['is_hot' => 'N']);
                }
            }
            return response()->json(['message'=>'success','code'=>'200','data'=>'Buyer removed successfully']);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

}
