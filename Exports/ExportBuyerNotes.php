<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use App\Models\BuyerUserNotes;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\DB;

class ExportBuyerNotes implements FromCollection, WithHeadings
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
        $query = BuyerUserNotes::select('buyer_user_notes.id', 'buyer_user_notes.buyer_id', 'buyer_user_notes.listing_id', DB::raw('CONCAT(agents.firstname, " ", agents.lastname) as agent_name'), 'buyer_user_notes.business_name', DB::raw("ExtractValue(buyer_user_notes.note_text, '//text()') as note_texts"), DB::raw('DATE_FORMAT(buyer_user_notes.created_at, "%d-%b-%Y") as SubmitDate'));

        $query->leftJoin('agents', 'agents.id','=','buyer_user_notes.agent_id');

        if(isset($this->input['start_date']) && !empty($this->input['start_date']) && isset($this->input['end_date']) && !empty($this->input['end_date'])){

            $start_date = date($this->input['start_date']);
            $end_date = date($this->input['end_date']);

            $query->whereDate('buyer_user_notes.created_at', '>=', $start_date)->whereDate('buyer_user_notes.created_at', '<=', $end_date);

        }

        $buyer_notes = $query->get();

        return $buyer_notes; 
    }

    public function headings(): array
    {
        return [
            'NoteID',
            'BuyerID',
            'ListingID',
            'AgentName',
            'BusinessName',
            'Note',
            'SubmitDate'
        ];
    }

}
