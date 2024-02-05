<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\DB;
use App\Models\FranchiseLead;
use App\Models\FranchiseLeadNote;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class FranchiseLeadsHotReportExport implements FromCollection, WithHeadings, WithEvents
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
        $leads = FranchiseLead::select('id', 'businessname', 'cellphone')->where('agent_id', $this->input['agent'])->get();

        $arr= [];

        foreach($leads as $key => $lead){
            $arr[]= array(
                "ID #" => $lead->id,	
                "Lead Name" => $lead->businessname,
                "Latest Note" => $this->latest_note($lead->id) ? $this->latest_note($lead->id): "",	
                "Date of Note" => $this->date_of_note($lead->id) ? $this->date_of_note($lead->id): "",	
                "Owner Phone Email" => $lead->cellphone
        );
        }
        return collect($arr);
    }

    public function headings(): array
    {
        return [
            "ID #",	
            "Lead Name",
            "Latest Note",	
            "Date of Note",	
            "Owner Phone Email"
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

    public function latest_note($id){
        $result = FranchiseLeadNote::select('notetxt')->where('lead_id', $id)->orderBy('id', 'DESC')->first();
        if(isset($result)) {
            $result = isset($result->notetxt)?$result->notetxt:'';
        } else {
            $result = "";
        }

        return strip_tags($result);
    }

    public function date_of_note($id){
        $result = FranchiseLeadNote::select('created_at')->where('lead_id', $id)->orderBy('id', 'DESC')->first();
        if(isset($result)) {
            $result = isset($result->created_at)?$result->created_at->format('Y-m-d'):'';
        } else {
            $result = "";
        }
        return $result;
    }

}
