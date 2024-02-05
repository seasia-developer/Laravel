<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Models\BuyerUserNotes;
use App\Models\ListingSeller;
use App\Models\Listing;
use App\Mail\BuyerNoteMail;
use App\Models\Buyers;
use App\Models\Agents;
use App\Models\BuyerUserHot;

class EmailAndNotesController extends Controller
{
    public function index(Request $request, $id){
        try {

            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $query = BuyerUserNotes::select('id', 'listing_id', 'business_name', 'related_to', 'note_text', 'agent_id', 'email_to_seller', 'email_to_agent', 'email_to_other', 'created_at')->where('buyer_id', $id);

            if($request->filled('listing_id')) {
                $query->where('listing_id', $request->listing_id);
            }

            if($request->filled('related_to')) {
                $query->where('related_to', $request->related_to);
            }

            if($request->filled('note_text')) {
                $query->where('note_text', 'LIKE', '%' . $request->note_text . '%');
            }

            $query->with(['agent' => function($agent) {
                $agent->select('id', 'username', 'type');
            }]);

            $notes = $query->latest()->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$notes]);
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }
    
    public function add_note(Request $request, $id){
        try {
            $data = $request->all();

            if(!empty($data['listing_id'])){
                $listing_id = $data['listing_id'];
                $listing_id_array = explode(',', $listing_id);
                $listing_id_array_trimmed = array_map('trim', $listing_id_array);

                foreach ($listing_id_array_trimmed as $get_listing) {
                    $data['listing_id'] = $get_listing;

                    $data['buyer_id'] = $id;

                    $listing = Listing::select('id', 'bname', 'olagent', 'buyer_email')->find($data['listing_id']);

                    $data['business_name'] = $listing->bname;
                    $data['agent_id'] = Auth::id();

                    $seller = ListingSeller::select('id', 'oemailaddress')->where('listing_id', $data['listing_id'])->first();

                    $agent = Agents::select('id', 'email')->find($listing->olagent);


                    $to = [];
            
                    if($data['email_to_seller'] == 'Y' && !empty($seller->oemailaddress)){
                        $to['seller'] = $seller->oemailaddress;
                    }
            
                    if($data['email_to_agent'] == 'Y' && !empty($agent->email)){
                        $to['agent'] = $agent->email;
                    }

                    if($data['email_to_buyer'] == 'Y' && !empty($listing->buyer_email)){
                        $to['buyer'] = $listing->buyer_email;
                    }

                    if($data['email_to_buyer'] == 'Y' && empty($listing->buyer_email)){
                        $buyer = Buyers::select('email')->find($id);
                        $to['buyer'] = $buyer->email;
                    }
            
                    if($data['email_to_seller'] == 'Y' && empty($seller->oemailaddress)){
                        return response()->json(['message'=>'error','code'=>'302','data'=>'The seller email address is not filled.']);
                    }

                    if($data['email_to_agent'] == 'Y' && empty($agent->email)){
                        return response()->json(['message'=>'error','code'=>'302','data'=>'The agent email address is not filled.']);
                    }

                    if(count($to) == 0 && empty($data['email_to_other']) && isset($data['subject_name'])) {
                        return response()->json(['message'=>'error','code'=>'302','data'=>'Please choose one option from Send Email To to proceed
                        ']);
                    }

                    if(isset($data['subject_name'])) {
                        $subject_name = $data['subject_name'];
                    } else {
                        $subject_name = 'A new buyer note added on listing id '.$data['listing_id'];
                    }

                    $mailData = [
                        'subject_name' => $subject_name,
                        'text_body' => $data['note_text'],
                    ];

                    if(!empty($data['email_to_other'])){
                        $to_other = $data['email_to_other'];
                        $to_other_array = explode(',', $to_other);
                        $to_other_array_trimmed = array_map('trim', $to_other_array);

                        foreach ($to_other_array_trimmed as $recipient_other) {
                            if(validEmail($recipient_other)){
                                Mail::to($recipient_other)->queue(new BuyerNoteMail($mailData));
                            }
                        }
                    }

                    if(count($to) > 0) {
                        foreach ($to as $recipient) {
                            if(validEmail($recipient)){
                                Mail::to($recipient)->queue(new BuyerNoteMail($mailData));
                            }
                        }
                    }
            
                    $model = new BuyerUserNotes();
                    $prefix = 'buyer_user_note';
                    $delete_from_redis = null;
                    $note = redisCreate($model, $data, $prefix, $delete_from_redis);

                    
                    // Buyer User Hot Agent
                    if(isset($agent->id) && $data['is_hot'] == 1){
                        $input_2['buyer_id'] = $id;
                        $input_2['agent_id'] = $agent->id;
                        $model_2 = new BuyerUserHot();
                        $prefix_2 = 'buyer_user_hot_'.$id.'_'.$agent->id;
                        $delete_from_redis_2 = 'buyer_hotlist_'.$id;
                        $input_match_2 = [
                            'buyer_id'   => $id,
                            'agent_id'   => $agent->id
                        ];
                        $buyer_user_hot = redisUpdateOrCreateForRetrieve($model_2, $input_match_2, $input_2, $prefix_2, $delete_from_redis_2);

                    }
                    // end Buyer User Hot Agent
                    
                }
            }
    
            return response()->json(['message'=>'success','code'=>'200','data'=>'Note Created Successfully']);
        } catch(\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function show($id) {
        try {
            $model = new BuyerUserNotes(); 
            $prefix = 'buyer_user_note';
            $select_column = ['id','listing_id','note_text', 'is_hot','related_to','email_to_seller','email_to_agent','email_to_other', 'email_to_buyer', 'agent_id', 'created_at'];

            $note = redisFind($model, $select_column, $id, $prefix);

            $seller = ListingSeller::select('id', 'oemailaddress')->where('listing_id', $note->listing_id)->first();

            $listing = Listing::find($note->listing_id);
            $agent = Agents::select('email')->find($listing->olagent);
            
            $to = null;
            if($note->email_to_seller == 'Y' && !empty($seller->oemailaddress)){
                $to['seller'] = $seller->oemailaddress;
            }

            if($note->email_to_agent == 'Y' && !empty($agent->email)){
                $to['agent'] = $agent->email;
            }

            if($note->email_to_buyer == 'Y' && !empty($listing->buyer_email)){
                $to['buyer'] = $listing->buyer_email;
            }

            if(!empty($note->email_to_other)){
                $to['other'] = $note->email_to_other;
            }

            $note->send_to = $to;

            return response()->json(['message'=>'success','code'=>'200','data'=>$note]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

}
