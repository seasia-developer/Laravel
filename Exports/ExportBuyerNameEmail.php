<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use App\Models\Buyers;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\DB;
use App\Models\ListingMarket;


class ExportBuyerNameEmail implements FromCollection, WithHeadings
{
    protected $input;

    function __construct($input) {
            $this->input = $input;
    }

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $query = Buyers::select('buyer_users.firstname', 'buyer_users.lastname', 'buyer_users.email', 'buyer_users.phoneno', 'buyer_users.cellno', 'buyer_users.willlistingno', 'buyer_users.agentid', 'agents.firstname as agent_firstname', 'agents.lastname as agent_lastname', 'buyer_users.state', 'buyer_users.lookingstates', 'buyer_users.sourcehear', DB::raw('DATE_FORMAT(buyer_users.created_at, "%d-%b-%Y") as date_created'));

        $query->leftJoin('agents', 'agents.id','=','buyer_users.agentid');

        if(isset($this->input['own_restaurant']) && !empty($this->input['own_restaurant']) && $this->input['own_restaurant'] != 'A'){
            $query->where('isown', $this->input['own_restaurant']);
        }
        
        if(isset($this->input['cash_available']) && !empty($this->input['cash_available'])){
            $cash_available = explode(' ', $this->input['cash_available']);
            $query->whereIn('cashinhand', $cash_available);
        }

        if(isset($this->input['desiredRestaurant']) && !empty($this->input['desiredRestaurant'])){
            $desiredRestaurants = explode(',', $this->input['desiredRestaurant']);
            foreach($desiredRestaurants as $desiredRestaurant) {
                $query->orWhereRaw('FIND_IN_SET("'.$desiredRestaurant.'",desiredRestaurant)');
            };
        }

        if(isset($this->input['lookingStates']) && !empty($this->input['lookingStates'])){
            $lookingStates = explode(',', $this->input['lookingStates']);
            foreach($lookingStates as $lookingState) {
                $query->orWhereRaw('FIND_IN_SET("'.$lookingState.'",lookingStates)');
            };
        }

        if(isset($this->input['start_date']) && !empty($this->input['start_date']) && isset($this->input['end_date']) && !empty($this->input['end_date'])){
            $start_date = date($this->input['start_date']);
            $end_date = date($this->input['end_date']);
            $query->whereDate('buyer_users.created_at', '>=', $start_date)->whereDate('buyer_users.created_at', '<=', $end_date);
        }

        $buyers = $query->get();

        foreach ($buyers as $key => $buyer) {
            $buyer->lookingstates = $buyer->lookingstates ? $this->market($buyer->lookingstates): "";
        }

        return $buyers;  

    }

    public function headings(): array
    {
        return [
            'First Name',
            'Last Name',
            'Email',
            'Contact Phone',
            'Cell Phone',
            'Listing Number',
            'Copy to Agent',
            'Agent First Name',
            'Agent Last Name',
            'State',
            'Where they are looking to BUY a Restaurant?',
            'How Did you Find Us?',
            'Date Created'
        ];
    } 

    public function market($ids){
        $result = ListingMarket::whereIn('id', [$ids])->get();
        $market_list = '';
        foreach ($result as $val){
            $market_list .= $val['name'].','; 
        }
        $market_list = trim($market_list, ',');
        return $market_list;
    }

    

}
