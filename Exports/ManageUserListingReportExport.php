<?php

namespace App\Exports;

use App\Models\Agents;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use App\Models\Listing;
use App\Models\ListingMarket;
use App\Models\ListingCategory;
use App\Models\ListingSeller;
use App\Models\CaBuyers;
use App\Models\InContractAndLOI;
use App\Models\ListingCounty;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class ManageUserListingReportExport implements FromCollection, WithHeadings, WithEvents
{
    protected $input;

    function __construct($input) {
            $this->input = $input;
    }

    public function collection()
    {  
        $query = Listing::select('id', 'bname', 'baddress', 'bcity', 'bstate', 'bzip', 'bcounty', 'burldes', 'bheadlinead', 'bsaleprice', 'bprivatelist', 'bacquiredlist', 'bgradelist', 'isBat', 'bstatuslist', 'bprequalification', 'bamount', 'showcity', 'bexpiredate', 'buyer_email', 'bcommissionamount', 'daysonmarket')->where('is_duplicate', '0');

        if(isset($this->input['status']) && !empty($this->input['status'])) {
            $query->where('bstatuslist', $this->input['status']);
        }

        if(isset($this->input['agent']) && !empty($this->input['agent'])) {
            $query->where('olagent', $this->input['agent']);
        }

        if(isset($this->input['office']) && !empty($this->input['office'])) {
            $query->where('franchiseofficeid', $this->input['office']);
        }

        if(isset($this->input['from_date']) && !empty($this->input['from_date']) && isset($this->input['to_date']) && !empty($this->input['to_date'])) {
            $from = date($this->input['from_date']);
            $to   = date($this->input['to_date']);

            if(isset($this->input['status']) && $this->input['status'] == 'Sold') {
                $query->whereBetween('bsolddate', [$from, $to]);
            } 
            elseif(isset($this->input['status']) && $this->input['status'] == 'Expired'){
                $query->whereBetween('bexpiredate', [$from, $to]);
            }
            elseif(isset($this->input['status']) && $this->input['status'] == 'Cancelled'){
                $query->whereBetween('bcanceldate', [$from, $to]);
            }
            else {
                $query->whereBetween('created_at', [$from, $to]);
            }
        }
            
        $listings = $query->orderBy('id', 'DESC')->get();

        $arr= [];
        
        foreach($listings as $key => $listing){
            $arr[]= array(
                
                "Listing#" => $listing->id,	
                "Business Name" => $listing->bname,	
                "Business Address" => $listing->baddress,	
                "Owner Legal Name" => $this->owner_legal_name($listing->id) ? $this->owner_legal_name($listing->id): "",
                "City" => $listing->bcity,
                "State" => $listing->bstate,
                "Zip" => $listing->bzip,
                "County" => $listing->bcounty ? $this->county($listing->bcounty): "",
                "LISTING AGENT" => $listing->olagent ? $this->agent($listing->olagent): "",
                "Market" => $listing->bregion ? $this->market($listing->bregion): "",
                "Office" => $listing->franchisename ? $this->franchisename($listing->franchisename): "",
                "Business Type" => $listing->btype ? $this->business_type($listing->btype): "",
                "URL Description" => $listing->burldes,	
                "Headline Ad" => $listing->bheadlinead,
                "Sale Price" => $listing->bsaleprice,
                "Private Listing" => $listing->bprivatelist,
                "How Acquired Listing" => $listing->bacquiredlist,
                "Listing Grade" => $listing->bgradelist,
                "Number of CAs signed" => $this->number_CAs_signed($listing->id) ? $this->number_CAs_signed($listing->id): "",
                "Is BAT" => $listing->isBat,
                "Listing Status" => $listing->bstatuslist,
                "Buyer Prequalification Yes/No" => $listing->bprequalification,
                "Amount" => $listing->bamount,
                "City Confidential Yes/No" => $listing->showcity == '1' ? 'Yes' : 'No',
                "BUYER'S AGENT" => $this->buyers_agent($listing->id) ? $this->buyers_agent($listing->id): "",
                "Expire Date" => $listing->bexpiredate,
                "Buyer Email Address" => $listing->buyer_email,
                "Commission" => $listing->bcommissionamount,
                "Referral Fee Amount" => $this->ref_fee_per($listing->id) ? $this->ref_fee_per($listing->id): "",
                "Other" => $this->key_number($listing->id) ? $this->key_number($listing->id): "",
                "Days On Market" => $listing->daysonmarket
        );

        }
        return collect($arr);

    }

    public function headings(): array
    {
        return [
            "Listing#",	
            "Business Name",	
            "Business Address",	
            "Owner Legal Name",
            "City",
            "State",
            "Zip",
            "County",
            "LISTING AGENT",
            "Market",
            "Office",
            "Business Type",	
            "URL Description",
            "Headline Ad",
            "Sale Price",
            "Private Listing",
            "How Acquired Listing",
            "Listing Grade",
            "Number of CAs signed",
            "Is BAT",
            "Listing Status",
            "Buyer Prequalification Yes/No",
            "Amount",
            "City Confidential Yes/No",
            "BUYER'S AGENT",
            "Expire Date",
            "Buyer Email Address",
            "Commission",
            "Referral Fee Amount",
            "Other",
            "Days On Market"
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function(AfterSheet $event) {
                $event->sheet->getDelegate()->getStyle('A1:AT1')->getFont()->setBold(true);
            },
        ];
    }

    public function agent($id){
        $agent = Agents::select('firstname', 'lastname')->find($id);
        if(isset($agent)) {
            $result = $agent->firstname." ".$agent->lastname;
        } else {
            $result = "";
        }
        return $result;
    }


    public function market($id){
        $market = ListingMarket::select('name')->find($id);
        if(isset($market)) {
            $result = $market->name;
        } else {
            $result = "";
        }
        return $result;
    }

    public function franchisename($id){
        $agent = Agents::select('franchisename')->find($id);
        if(isset($agent)) {
            $result = $agent->franchisename;
        } else {
            $result = "";
        }
        return $result;
    }

    public function business_type($id){
        $agent = ListingCategory::select('name')->find($id);
        if(isset($agent)) {
            $result = $agent->name;
        } else {
            $result = "";
        }
        return $result;
    }

    public function owner_legal_name($id){
        $seller = ListingSeller::select('olegalname1')->where('listing_id', $id)->first();
        if(isset($seller)) {
            $result = $seller->olegalname1;
        } else {
            $result = "";
        }
        return $result;
    }


    public function number_CAs_signed($id){
        $ca = CaBuyers::select('nosigned')->where('nosigned', '!=', '0')->where('listing_id', $id)->count();
        if(isset($ca)) {
            $result = $ca;
        } else {
            $result = "";
        }
        return $result;
    }

    public function ref_fee_per($id){
        $listing = InContractAndLOI::select('ref_fee_per')->where('listing_id', $id)->first();
        if(isset($listing)) {
            $result = $listing->ref_fee_per;
        } else {
            $result = "";
        }
        return $result;
    }


    public function key_number($id){
        $listing = InContractAndLOI::select('key_number')->where('listing_id', $id)->first();
        if(isset($listing)) {
            $result = $listing->key_number;
        } else {
            $result = "";
        }
        return $result;
    }

    public function buyers_agent($id){
        $listing_agent = Listing::select('olagent')->find($id);
        $listing = InContractAndLOI::select('bagentname', 'sagentname')->where('listing_id', $id)->first();
        $username="";
        if(isset($listing->bagentname) && $listing->bagentname != "Others"){
            $agent = Agents::select('firstname', 'lastname')->find($listing->bagentname);
            if(isset($agent)) {
                $username = $agent->firstname." ".$agent->lastname;
            }
        }else if(isset($listing->bagentname) && $listing->bagentname == "Others"){
            $username = isset($listing->sagentname)?$listing->sagentname:"";
        }else {
            $agent = Agents::select('firstname', 'lastname')->find($listing_agent->olagent);
             if(isset($agent)) {
                $username = $agent->firstname." ".$agent->lastname;
            }
        } 
        return $username;
    }

    public function county($id){
        $county = ListingCounty::select('name')->find($id);
        if(isset($county)) {
            $result = $county->name;
        } else {
            $result = "";
        }
        return $result;
    }

}
