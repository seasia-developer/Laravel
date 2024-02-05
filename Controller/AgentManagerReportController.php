<?php

namespace App\Http\Controllers\Api\ListingReports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Listing;

class AgentManagerReportController extends Controller
{
    public function index(Request $request){
        try {
            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $query = Listing::select('id', 'bname', 'bcity', 'bstate', 'bsaleprice', 'bcommissionamount', 'olagent', 'bstatuslist', 'bexpiredate', 'bclosingdate', 'bcanceldate', 'bsolddate', 'daysonmarket', 'buyers_id')->where('is_duplicate', '0');

            $query->with(['inContractAndLOI' => function($q) {
                $q->select('id', 'listing_id', 'sellingprice', 'bagentname', 'sagentname', 'amountpay', 'dateonpay', 'ref_fee_per', 'key_number')
                ->with(['bagent' => function($bagent) {
                    $bagent->select('id', 'firstname', 'lastname');
                }]);
            }]); 

            $query->with(['listingcanceled' => function($q) {
                $q->select('id', 'listing_id', 'escrowamount');
            }]); 

            $query->with(['agent' => function($q) {
                $q->select('id', 'firstname', 'lastname');
            }]);

            $query->with(['buyer' => function($q) {
                $q->select('id', 'firstname', 'lastname');
            }]);

            if($request->filled('agent')) {
                $query->where('olagent', $request->agent);
            }

            if($request->filled('office')) {
                $query->where('franchiseofficeid', $request->office);
            }

            if($request->filled('from_date') && $request->filled('to_date')) {
                $from = date($request->from_date);
			    $to   = date($request->to_date);

                if($request->filled('status') && $request->status == 'Sold') {
                    $query->whereBetween('bsolddate', [$from, $to]);
                } 
                elseif($request->filled('status') && $request->status == 'Expired'){
                    $query->whereBetween('bexpiredate', [$from, $to]);
                }
                elseif($request->filled('status') && $request->status == 'Cancelled'){
                    $query->whereBetween('bcanceldate', [$from, $to]);
                }
                else {
                    $query->whereBetween('created_at', [$from, $to]);
                }
            }
            
            switch($request->filter) {
                case('Total Listings Available'):
                    $query->where('bstatuslist', 'Available');
                    break;
                case('Total Listing Available-Not Advertised'):
                    $query->where('bstatuslist', 'Available not Advertised');
                    break;
                case('Total Listing Coming Soon'):
                    $query->where('bstatuslist', 'Coming Soon');
                    break;
                case('Total Listing In Contract'):
                    $query->where('bstatuslist', 'In Contract');
                    break;
                case('Total Listing in LOI'):
                    $query->where('bstatuslist', 'LOI');
                    break;
                case('Total Listings Sold'):
                    $query->where('bstatuslist', 'Sold');
                    break;
                case('Total Expired Listings'):
                    $query->where('bstatuslist', 'Expired');
                    break;
                case('Total Cancelled Listings'):
                    $query->where('bstatuslist', 'Cancelled');
                    break;
                default:
            }

            if($request->filled('search')){
                $searchFields = ['id', 'bname', 'bcity', 'bstate', 'bsaleprice'];
                $query->where(function($query) use($request, $searchFields){
                  $searchWildcard = '%' . $request->search . '%';
                  foreach($searchFields as $field){
                    $query->orWhere($field, 'LIKE', $searchWildcard);
                  }
                });
            }

            $listings = $query->orderBy('id', 'DESC')->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$listings]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }
}
