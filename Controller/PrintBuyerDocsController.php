<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Listing;
use App\Models\Buyers;
use App\Models\ListingSeller;
use App\Models\ListingOffice;
use App\Models\ListingEquipment;
use App\Models\ApaBuyersDoc;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Vendors;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use App\Models\BuyerUserDocument;
use App\Models\AmendmentBuyersDoc;
use App\Models\TerminateBuyersDoc;
use App\Models\ConfidentialityBuyersDoc;
use Illuminate\Support\Facades\Auth;

class PrintBuyerDocsController extends Controller
{
    public function asset_purchase($buyer_id, $listing_id, $doc_id=null){
        try {
            $listing = Listing::find($listing_id);
            $buyer = Buyers::find($buyer_id);
            $seller = ListingSeller::firstWhere('listing_id', $listing_id);

            $equipment = ListingEquipment::firstWhere('listing_id', $listing_id);

            $agreement = [];

            if(isset($doc_id)) {
                $doc = ApaBuyersDoc::find($doc_id);

                if(isset($doc)) {
                    $agreement = $doc;
                }

            } else {

            $agreement['listingid'] = $listing_id;
            $agreement['buyerId'] = $buyer_id;
            $agreement['check_state_hid'] =  isset($listing->bstate)?$listing->bstate:'';
            $agreement['buyer_legel_name'] = isset($buyer->buyerlegalname)?$buyer->buyerlegalname:'';
            
            $buyer_staddress = isset($buyer->staddress)?$buyer->staddress:'';
            $buyer_city = isset($buyer->city)?$buyer->city:'';
            $buyer_state = isset($buyer->state)?$buyer->state:'';
            $buyer_postalcode = isset($buyer->postalcode)?$buyer->postalcode:'';
            $agreement['buyer_address'] = $buyer_staddress.', '.$buyer_city.', '.$buyer_state.', '.$buyer_postalcode;

            $agreement['seller_name'] = isset($seller->olegalname1)?$seller->olegalname1:'';
            $agreement['seller_trade_name'] = isset($listing->bname)?$listing->bname:'';
            
            $listing_baddress = isset($listing->baddress)?$listing->baddress:'';
            $listing_bcity = isset($listing->bcity)?$listing->bcity:'';
            $listing_bstate = isset($listing->bstate)?$listing->bstate:'';
            $listing_bzip = isset($listing->bzip)?$listing->bzip:'';
            
            $agreement['seller_business_address'] = $listing_baddress.', '.$listing_bcity.', '.$listing_bstate.', '.$listing_bzip;

            $agreement['fld_38_2_a'] = isset($listing->bcounty)?$listing->bcounty:''; //relation

            $listing_office_agent_firstname = isset($listing->office->agent->firstname)?$listing->office->agent->firstname:'';
            $listing_office_agent_lastname = isset($listing->office->agent->lastname)?$listing->office->agent->lastname:'';

            $agreement['fld_57_d'] = $listing_office_agent_firstname.' '.$listing_office_agent_lastname;

            $agreement['fld_57_i'] = $listing_office_agent_firstname.' '.$listing_office_agent_lastname;

            $agreement['fld_57_a'] = isset($listing->office->olegalname)?$listing->office->olegalname:'';

            $agreement['fld_57_b'] = isset($listing->office->officeaddress)?$listing->office->officeaddress:'';

            $agreement['fld_57_g'] = isset($listing->office->officeaddress)?$listing->office->officeaddress:'';

            $listing_office_officecity = isset($listing->office->officecity)?$listing->office->officecity:'';
            $listing_office_officestate = isset($listing->office->officestate)?$listing->office->officestate:'';
            $listing_office_officezip = isset($listing->office->officezip)?$listing->office->officezip:'';

            $agreement['fld_57_c'] = $listing_office_officecity.', '.$listing_office_officestate.', '.$listing_office_officezip;

            $agreement['fld_57_h'] = $listing_office_officecity.', '.$listing_office_officestate.', '.$listing_office_officezip;

            $agreement['fld_57_e'] = isset($listing->office->agent->cellphone)?$listing->office->agent->cellphone:'';
            $agreement['fld_57_j'] = isset($listing->office->agent->cellphone)?$listing->office->agent->cellphone:'';

            $agreement['buyer_binding_e'] = isset($buyer->buyerlegalname)?$buyer->buyerlegalname:'';

            $agreement['buyer_binding_f'] = isset($buyer->staddress)?$buyer->staddress:'';

            $buyer_city = isset($buyer->city)?$buyer->city:'';
            $buyer_state = isset($buyer->state)?$buyer->state:'';
            $buyer_postalcode = isset($buyer->postalcode)?$buyer->postalcode:'';
            $agreement['buyer_binding_h'] = $buyer_city.', '.$buyer_state.', '.$buyer_postalcode;

            $buyer_phoneno = isset($buyer->phoneno)?$buyer->phoneno:'';
            $buyer_cellno = isset($buyer->cellno)?$buyer->cellno:'';
            $agreement['buyer_binding_j'] = $buyer_phoneno.' - '.$buyer_cellno;

            $agreement['seller_acceptance_g'] = isset($seller->olegalname1)?$seller->olegalname1:'';
            $agreement['seller_acceptance_h'] = isset($seller->ohomeaddress)?$seller->ohomeaddress:'';

            $seller_ocity = isset($seller->ocity)?$seller->ocity:'';
            $seller_ostate = isset($seller->ostate)?$seller->ostate:'';
            $seller_ozip = isset($seller->ozip)?$seller->ozip:'';
            $agreement['seller_acceptance_j'] = $seller_ocity.', '.$seller_ostate.', '.$seller_ozip;

            $seller_obusinessphone = isset($seller->obusinessphone)?$seller->obusinessphone:'';
            $seller_ocell = isset($seller->ocell)?$seller->ocell:'';
            $agreement['seller_acceptance_l'] = $seller_obusinessphone.' - '.$seller_ocell;

            $agreement['seller_rejection_g'] = isset($seller->olegalname1)?$seller->olegalname1:'';
            $agreement['seller_rejection_h'] = isset($seller->ohomeaddress)?$seller->ohomeaddress:'';
            $agreement['seller_rejection_j'] = $seller_ocity.', '.$seller_ostate.', '.$seller_ozip;
            $agreement['seller_rejection_l'] = $seller_obusinessphone.' - '.$seller_ocell;

            $agreement['seller_counter_offer_g'] = isset($seller->olegalname1)?$seller->olegalname1:'';
            $agreement['seller_counter_offer_h'] = isset($seller->ohomeaddress)?$seller->ohomeaddress:'';
            $agreement['seller_counter_offer_j'] = $seller_ocity.', '.$seller_ostate.', '.$seller_ozip;
            $agreement['seller_counter_offer_l'] = $seller_obusinessphone.' - '.$seller_ocell;

            $agreement['equipment_list'] = isset($equipment->equiptext)?$equipment->equiptext:'';

            $agreement['fld_50_a'] = isset($listing->office->olegalname)?$listing->office->olegalname:'';

            }

            return response()->json(['message'=>'success','code'=>'200','data'=>$agreement]);
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function asset_purchase_create($buyer_id, $listing_id, Request $request){
        try {

            $data = $request->all();

            $data['vendor'] = Vendors::find($data['total_purchase_price_c_deposits']);

            $pdf = Pdf::loadView('pdf.asset-purchase-contract', ['data' => $data])->setPaper('A4', 'portrait');

            $pdf->render();

            $content = $pdf->download()->getOriginalContent();
            
            $today = date('M-d-Y_H:i:s');

            $doc_file = 'APA_for_listing_'.$listing_id.'_Buyer_'.$buyer_id.'_'.$today.'.pdf';

            $doc_title = str_replace('_', ' ', preg_replace('/\.\w+$/', '', $doc_file));

            Storage::put('public/buyer/docs/'.$doc_file,$content);

            $input = $request->all();
            $input['listingId'] = $listing_id;
            $input['buyerId'] = $buyer_id;

            $model = new ApaBuyersDoc();
            $prefix = 'apa_buyers_doc';
            $delete_from_redis = null;
            $apa_buyers_doc = redisCreate($model, $input, $prefix, $delete_from_redis);

            $input2['doc_file'] = $doc_file;
            $input2['doc_title'] = $doc_title;
            $input2['buyer_id'] = $buyer_id;
            $input2['doc_agent'] = Auth::id();
            $input2['agent_type'] = Auth::user()->type;
            $input2['apa_document_id'] = $apa_buyers_doc->id;
            BuyerUserDocument::create($input2);

            return response()->json(['message'=>'success','code'=>'200','data'=>'Asset Purchase Contract Sucessfully Created']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function asset_purchase_update($doc_id,  Request $request){
        try {
            $doc = ApaBuyersDoc::find($doc_id);

            $data = $request->all();

            $data['vendor'] = Vendors::find($data['total_purchase_price_c_deposits']);

            $pdf = Pdf::loadView('pdf.asset-purchase-contract', ['data' => $data])->setPaper('A4', 'portrait');

            $pdf->render();

            $content = $pdf->download()->getOriginalContent();
            
            $today = date('M-d-Y_H:i:s');

            $doc_file = 'APA_for_listing_'.$doc->listingId.'_Buyer_'.$doc->buyerId.'_'.$today.'.pdf';

            $doc_title = str_replace('_', ' ', preg_replace('/\.\w+$/', '', $doc_file));

            Storage::put('public/buyer/docs/'.$doc_file,$content);
            
            $input = $request->all();
            $input['listingId'] = $doc->listingId;
            $input['buyerId'] = $doc->buyerId;

            $model = new ApaBuyersDoc();
            $prefix = 'apa_buyers_doc';
            $delete_from_redis = null;

            $apa_buyers_doc = redisUpdate($model, $input, $doc_id, $prefix, $delete_from_redis);

            $buyer_user_document = BuyerUserDocument::firstWhere('apa_document_id', $doc_id);
            Storage::delete('public/buyer/docs/'.$buyer_user_document->doc_file);

            $input2['doc_file'] = $doc_file;
            $input2['doc_title'] = $doc_title;
            $input2['buyer_id'] = $doc->buyerId;
            $input2['doc_agent'] = Auth::id();
            $input2['agent_type'] = Auth::user()->type;
            BuyerUserDocument::where('apa_document_id', $doc->id)->update($input2);

            return response()->json(['message'=>'success','code'=>'200','data'=>'Asset Purchase Contract Sucessfully Updated']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }


    public function amendement_to_apa($buyer_id, $listing_id, $doc_id=null){
        try {
            $listing = Listing::find($listing_id);
            $buyer = Buyers::find($buyer_id);
            $seller = ListingSeller::firstWhere('listing_id', $listing_id);
            
            $agreement = [];

            if(isset($doc_id)) {
                $doc = AmendmentBuyersDoc::find($doc_id);

                if(isset($doc)) {
                    $agreement = $doc;
                }

            } else {
                $agreement['listing_id'] = $listing_id;
                $agreement['buyer_id'] = $buyer_id;
                $agreement['check_state_hid'] = isset($listing->bstate)?$listing->bstate:'';
                $agreement['business_name'] = isset($listing->bname)?$listing->bname:'';

                
                $listing_baddress = isset($listing->baddress)?$listing->baddress:'';
                $listing_bcity = isset($listing->bcity)?$listing->bcity:'';
                $listing_bstate = isset($listing->bstate)?$listing->bstate:'';
                $listing_bzip = isset($listing->bzip)?$listing->bzip:'';
                $agreement['address'] = $listing_baddress.', '.$listing_bcity.', '.$listing_bstate.', '.$listing_bzip;

                $agreement['buyerlegalname'] = isset($buyer->buyerlegalname)?$buyer->buyerlegalname:'';
                
                $buyer_firstname = isset($buyer->firstname)?$buyer->firstname:'';
                $buyer_lastname = isset($buyer->lastname)?$buyer->lastname:'';
                $agreement['buyername'] = $buyer_firstname.' '.$buyer_lastname;

                $agreement['sellerlegalname'] = isset($seller->olegalname1)?$seller->olegalname1:'';
                $agreement['sellername'] = isset($seller->olegalname2)?$seller->olegalname2:'';

                $agreement['selling_broker'] = isset($listing->office->olegalname)?$listing->office->olegalname:'';
                $agreement['officelegalname'] = isset($listing->office->olegalname)?$listing->office->olegalname:'';
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>$agreement]);
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }


    public function amendement_to_apa_create($buyer_id, $listing_id, Request $request){
        try {

            $data = $request->all();

            $pdf = Pdf::loadView('pdf.amendement-to-apa', ['data' => $data])->setPaper('A4', 'portrait');
            $pdf->render();

            $content = $pdf->download()->getOriginalContent();

            $today = date('M-d-Y_H:i:s');

            $doc_file = 'Amendment_for_listing_'.$listing_id.'_Buyer_'.$buyer_id.'_'.$today.'.pdf';

            $doc_title = str_replace('_', ' ', preg_replace('/\.\w+$/', '', $doc_file));

            Storage::put('public/buyer/docs/'.$doc_file,$content);

            $input = $request->except(['check_state_hid', 'business_name', 'address', 'buyerlegalname', 'buyername', 'sellerlegalname', 'sellername', 'officelegalname']);
            $input['listing_id'] = $listing_id;
            $input['buyer_id'] = $buyer_id;

            $model = new AmendmentBuyersDoc();
            $prefix = 'amendment_buyers_doc';
            $delete_from_redis = null;
            $amendment_buyers_doc = redisCreate($model, $input, $prefix, $delete_from_redis);

            $input2['doc_file'] = $doc_file;
            $input2['doc_title'] = $doc_title;
            $input2['buyer_id'] = $buyer_id;
            $input2['doc_agent'] = Auth::id();
            $input2['agent_type'] = Auth::user()->type;
            $input2['amendment_id'] = $amendment_buyers_doc->id;
            BuyerUserDocument::create($input2);


            return response()->json(['message'=>'success','code'=>'200','data'=>'Amendement to APA Agreement Sucessfully Created']);
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function amendement_to_apa_update($doc_id, Request $request){
        try {

            $doc = AmendmentBuyersDoc::find($doc_id);
            
            $data = $request->all();

            $pdf = Pdf::loadView('pdf.amendement-to-apa', ['data' => $data])->setPaper('A4', 'portrait');
            $pdf->render();

            $content = $pdf->download()->getOriginalContent();

            $today = date('M-d-Y_H:i:s');

            $doc_file = 'Amendment_for_listing_'.$doc->listing_id.'_Buyer_'.$doc->buyer_id.'_'.$today.'.pdf';

            $doc_title = str_replace('_', ' ', preg_replace('/\.\w+$/', '', $doc_file));

            Storage::put('public/buyer/docs/'.$doc_file,$content);

            $input = $request->except(['check_state_hid', 'business_name', 'address', 'buyerlegalname', 'buyername', 'sellerlegalname', 'sellername', 'officelegalname']);
            $input['listing_id'] = $doc->listing_id;
            $input['buyer_id'] = $doc->buyer_id;

            $model = new AmendmentBuyersDoc();
            $prefix = 'amendment_buyers_doc';
            $delete_from_redis = null;
            $amendment_buyers_doc = redisUpdate($model, $input, $doc_id, $prefix, $delete_from_redis);

            $buyer_user_document = BuyerUserDocument::firstWhere('amendment_id', $doc_id);
            Storage::delete('public/buyer/docs/'.$buyer_user_document->doc_file);


            $input2['doc_file'] = $doc_file;
            $input2['doc_title'] = $doc_title;
            $input2['buyer_id'] = $doc->buyer_id;
            $input2['doc_agent'] = Auth::id();
            $input2['agent_type'] = Auth::user()->type;
            BuyerUserDocument::where('amendment_id', $doc->id)->update($input2);

            return response()->json(['message'=>'success','code'=>'200','data'=>'Amendement to APA Agreement Sucessfully Updated']);
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function termination_and_release_escrow($buyer_id, $listing_id, $doc_id=null){
        try {
            $listing = Listing::find($listing_id);
            $buyer = Buyers::find($buyer_id);
            $seller = ListingSeller::firstWhere('listing_id', $listing_id);
            
            $agreement = [];

            if(isset($doc_id)) {
                $doc = TerminateBuyersDoc::find($doc_id);

                if(isset($doc)) {
                    $agreement = $doc;
                }

            } else {
                $agreement['listing_id'] = $listing_id;
                $agreement['buyer_id'] = $buyer_id;
                $agreement['check_state_hid'] = isset($listing->bstate)?$listing->bstate:'';
                $agreement['information'] = isset($listing->bname)?$listing->bname:'';
                $agreement['buyername'] = isset($buyer->buyerlegalname)?$buyer->buyerlegalname:'';
                $agreement['sellername'] = isset($seller->olegalname1)?$seller->olegalname1:'';
                $agreement['seller_broker'] = isset($listing->office->olegalname)?$listing->office->olegalname:'';
                $agreement['listing_broker'] = isset($listing->office->olegalname)?$listing->office->olegalname:'';
                $agreement['georgiaRealestateOfcnum'] = isset($listing->agent_details->licenseno)?$listing->agent_details->licenseno:'';
            }

            return response()->json(['message'=>'success','code'=>'200','data'=>$agreement]);
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }


    public function termination_and_release_escrow_create($buyer_id, $listing_id, Request $request){
        try {

            $data = $request->all();

            $pdf = Pdf::loadView('pdf.termination-and-release-escrow', ['data' => $data])->setPaper('A4', 'portrait');
            $pdf->render();

            $content = $pdf->download()->getOriginalContent();

            $today = date('M-d-Y_H:i:s');

            $doc_file = 'Termination_for_listing_'.$listing_id.'_Buyer_'.$buyer_id.'_'.$today.'.pdf';

            $doc_title = str_replace('_', ' ', preg_replace('/\.\w+$/', '', $doc_file));

            Storage::put('public/buyer/docs/'.$doc_file,$content);

            
            $input = $request->except(['check_state_hid', 'information', 'buyername', 'sellername', 'seller_broker', 'listing_broker']);

            $input['listing_id'] = $listing_id;
            $input['buyer_id'] = $buyer_id;
            $input['terminate'] = 'terminate';

            $model = new TerminateBuyersDoc();
            $prefix = 'termination_buyers_doc';
            $delete_from_redis = null;
            $termination_buyers_doc = redisCreate($model, $input, $prefix, $delete_from_redis);

            $input2['doc_file'] = $doc_file;
            $input2['doc_title'] = $doc_title;
            $input2['buyer_id'] = $buyer_id;
            $input2['doc_agent'] = Auth::id();
            $input2['agent_type'] = Auth::user()->type;
            $input2['terminate_id'] = $termination_buyers_doc->id;
            BuyerUserDocument::create($input2);

            return response()->json(['message'=>'success','code'=>'200','data'=>'Termination and Release Escrow Agreement Sucessfully Created']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function termination_and_release_escrow_update($doc_id, Request $request){
        try {
            $doc = TerminateBuyersDoc::find($doc_id);

            $data = $request->all();

            $pdf = Pdf::loadView('pdf.termination-and-release-escrow', ['data' => $data])->setPaper('A4', 'portrait');
            $pdf->render();

            $content = $pdf->download()->getOriginalContent();

            $today = date('M-d-Y_H:i:s');

            $doc_file = 'Termination_for_listing_'.$doc->listing_id.'_Buyer_'.$doc->buyer_id.'_'.$today.'.pdf';

            $doc_title = str_replace('_', ' ', preg_replace('/\.\w+$/', '', $doc_file));

            Storage::put('public/buyer/docs/'.$doc_file,$content);

            
            $input = $request->except(['check_state_hid', 'information', 'buyername', 'sellername', 'seller_broker', 'listing_broker']);

            $input['listing_id'] = $doc->listing_id;
            $input['buyer_id'] = $doc->buyer_id;

            $model = new TerminateBuyersDoc();
            $prefix = 'termination_buyers_doc';
            $delete_from_redis = null;
            $termination_buyers_doc = redisUpdate($model, $input, $doc_id, $prefix, $delete_from_redis);

            $buyer_user_document = BuyerUserDocument::firstWhere('terminate_id', $doc_id);
            Storage::delete('public/buyer/docs/'.$buyer_user_document->doc_file);

            $input2['doc_file'] = $doc_file;
            $input2['doc_title'] = $doc_title;
            $input2['buyer_id'] = $doc->buyer_id;
            $input2['doc_agent'] = Auth::id();
            $input2['agent_type'] = Auth::user()->type;
            BuyerUserDocument::where('terminate_id', $doc->id)->update($input2);

            return response()->json(['message'=>'success','code'=>'200','data'=>'Termination and Release Escrow Agreement Sucessfully Updated']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }



    public function confidentiality($buyer_id, $listing_id, $doc_id=null){
        try {
            $listing = Listing::find($listing_id);
            $buyer = Buyers::find($buyer_id);

            $agreement = [];

            if(isset($doc_id)) {
                $doc = ConfidentialityBuyersDoc::find($doc_id);

                if(isset($doc)) {
                    $agreement = $doc;
                }

            } else {
                $agreement['buyer_id'] = $buyer_id;
                $agreement['listing_id'] = $listing_id;

                $agreement['description'] = isset($listing->burldes)?$listing->burldes:'';

                $buyer_firstname = isset($buyer->firstname)?$buyer->firstname:'';
                $buyer_lastname = isset($buyer->lastname)?$buyer->lastname:'';
                $agreement['print_name'] = $buyer_firstname.' '.$buyer_lastname;

                $agreement['staddress'] = isset($buyer->staddress)?$buyer->staddress:'';

                $buyer_city = isset($buyer->city)?$buyer->city:'';
                $buyer_state = isset($buyer->state)?$buyer->state:'';
                $buyer_postalcode = isset($buyer->postalcode)?$buyer->postalcode:'';
                $agreement['fulladdress'] = $buyer_city.'/'.$buyer_state.'/'.$buyer_postalcode;

                $agreement['phoneno'] = isset($buyer->phoneno)?$buyer->phoneno:'';
                $agreement['email'] = isset($buyer->email)?$buyer->email:'';
                $agreement['company'] = isset($listing->office->olegalname)?$listing->office->olegalname:'';
                $agreement['officeaddress'] = isset($listing->office->agent->address)?$listing->office->agent->address:'';

                
                $listing_office_agent_city = isset($listing->office->agent->city)?$listing->office->agent->city:'';
                $listing_office_agent_state = isset($listing->office->agent->state)?$listing->office->agent->state:'';
                $listing_office_agent_zipcode = isset($listing->office->agent->zipcode)?$listing->office->agent->zipcode:'';
                $agreement['officefulladdress'] = $listing_office_agent_city.'/'.$listing_office_agent_state.'/'.$listing_office_agent_zipcode;

                $agreement['officetelephone'] = isset($listing->office->agent->cellphone)?$listing->office->agent->cellphone:'';
                $agreement['officefax'] = isset($listing->office->agent->officefax)?$listing->office->agent->officefax:'';

                $listing_office_agent_firstname = isset($listing->office->agent->firstname)?$listing->office->agent->firstname:'';
                $listing_office_agent_lastname = isset($listing->office->agent->lastname)?$listing->office->agent->lastname:'';
                $agreement['agentdetail'] = $listing_office_agent_firstname.' '.$listing_office_agent_lastname;

                $agreement['agent_email'] = isset($listing->office->agent->email)?$listing->office->agent->email:'';
            }
    
            return response()->json(['message'=>'success','code'=>'200','data'=>$agreement]);
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }


    public function confidentiality_create($buyer_id, $listing_id, Request $request){
        try {
            $data = $request->all();
            $data['listing_id'] = $listing_id;

            $pdf = Pdf::loadView('pdf.confidentiality', ['data' => $data])->setPaper('A4', 'portrait');
            
            $pdf->render();

            $content = $pdf->download()->getOriginalContent();

            $today = date('M-d-Y_H:i:s');

            $doc_file = 'Confidentiality_for_listing_'.$listing_id.'_Buyer_'.$buyer_id.'_'.$today.'.pdf';

            $doc_title = str_replace('_', ' ', preg_replace('/\.\w+$/', '', $doc_file));

            Storage::put('public/buyer/docs/'.$doc_file, $content);

            $input = $request->all();

            $input['listing_id'] = $listing_id;
            $input['buyer_id'] = $buyer_id;
            $input['universal'] = 'universal';

            $model = new ConfidentialityBuyersDoc();
            $prefix = 'confidentiality_buyers_doc';
            $delete_from_redis = null;
            $confidentiality_buyers_doc = redisCreate($model, $input, $prefix, $delete_from_redis);

            $input2['doc_file'] = $doc_file;
            $input2['doc_title'] = $doc_title;
            $input2['buyer_id'] = $buyer_id;
            $input2['doc_agent'] = Auth::id();
            $input2['agent_type'] = Auth::user()->type;
            $input2['universal_id'] = $confidentiality_buyers_doc->id;
            BuyerUserDocument::create($input2);

            return response()->json(['message'=>'success','code'=>'200','data'=>'Buyer Confidentiality Agreement Sucessfully Created']);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function confidentiality_update($doc_id, Request $request){
        try {
            $doc = ConfidentialityBuyersDoc::find($doc_id);
    
            $data = $request->all();
            $data['listing_id'] = $doc->listing_id;
    
            $pdf = Pdf::loadView('pdf.confidentiality', ['data' => $data])->setPaper('A4', 'portrait');
            $pdf->render();
    
            $content = $pdf->download()->getOriginalContent();
    
            $today = date('M-d-Y_H:i:s');
    
            $doc_file = 'Confidentiality_for_listing_'.$doc->listing_id.'_Buyer_'.$doc->buyer_id.'_'.$today.'.pdf';
    
            $doc_title = str_replace('_', ' ', preg_replace('/\.\w+$/', '', $doc_file));
    
            Storage::put('public/buyer/docs/'.$doc_file,$content);
    
            
            $input = $request->all();
            $input['listing_id'] = $doc->listing_id;
            $input['buyer_id'] = $doc->buyer_id;
    
            $model = new ConfidentialityBuyersDoc();
            $prefix = 'confidentiality_buyers_doc';
            $delete_from_redis = null;
            $confidentiality_buyers_doc = redisUpdate($model, $input, $doc_id, $prefix, $delete_from_redis);
    
            $buyer_user_document = BuyerUserDocument::firstWhere('universal_id', $doc_id);
            Storage::delete('public/buyer/docs/'.$buyer_user_document->doc_file);
    
            $input2['doc_file'] = $doc_file;
            $input2['doc_title'] = $doc_title;
            $input2['buyer_id'] = $doc->buyer_id;
            $input2['doc_agent'] = Auth::id();
            $input2['agent_type'] = Auth::user()->type;
            BuyerUserDocument::where('universal_id', $doc->id)->update($input2);
    
            return response()->json(['message'=>'success','code'=>'200','data'=>'Buyer Confidentiality Agreement Sucessfully Updated']);
    
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }
   
}
