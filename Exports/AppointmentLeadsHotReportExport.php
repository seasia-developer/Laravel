<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\DB;
use App\Models\BuyerLeads;
use App\Models\Agents;
use App\Models\User;
use App\Models\ListingLeadSource;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class AppointmentLeadsHotReportExport implements FromCollection, WithHeadings, WithEvents
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
        $leads = BuyerLeads::select('id', 'owner', 'businessname', 'agent_id', 'utype', 'uid', 'leadsource', 'homephone', 'email', DB::raw('DATE_FORMAT(buyer_leads.created_at, "%d-%b-%Y") as date_created'))->where('agent_id', $this->input['agent'])
        ->where('status', 'Appointment')->where('listingconvertstatus', '0')
        ->orderBy('id', 'ASC')
        ->get();

        $arr= [];

        foreach($leads as $key => $lead){
            $arr[]= array(
                "ID" => $lead->id,	
                "Owner" => $lead->owner,	
                "Restaurant Name" => $lead->businessname,
                "Agent" => $this->agent($lead->agent_id) ? $this->agent($lead->agent_id): "",
                "Creator" => $this->agent($lead->uid) ? $this->agent($lead->uid): "",
                "Status" => $lead->status,	
                "Source" => $this->source($lead->leadsource) ? $this->agent($lead->leadsource): "",	
                "Date" => $lead->date_created,	
                "Phone no" => $lead->homephone,	
                "Email" => $lead->email
        );
        }
        return collect($arr);
    }

    public function headings(): array
    {
        return [
            "ID",	
            "Owner",	
            "Restaurant Name",
            "Agent",	
            "Creator",
            "Status",	
            "Source",	
            "Date",	
            "Phone no",	
            "Email"
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

    public function source($id){
        $source = ListingLeadSource::select('name')->find($id);
        if(isset($source)) {
            $result = $source->name;
        } else {
            $result = "";
        }
        return $result;
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

    public function user($id){
        $user = User::select('username', 'type', 'firstname', 'lastname')->find($id);
        if(isset($user)) {
            if($user->type == '1' || $user->type == '2') {
                $result = $user->username;
            } else {
                $result = $user->firstname." ".$user->lastname;
            }
        } else {
            $result = "";
        }
        return $result;
    }
}
