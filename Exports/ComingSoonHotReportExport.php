<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Facades\DB;
use App\Models\Buyers;
use App\Models\Listing;

use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

class ComingSoonHotReportExport implements FromCollection, WithHeadings, WithEvents
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
        $listings = Listing::select('id', 'bname', 'bstatuslist', 'buyer_email', DB::raw('DATE_FORMAT(listing.created_at, "%d-%b-%Y") as date_created'))->where('bstatuslist', 'Coming Soon')->where('olagent', $this->input['agent'])->get();

        $arr= [];

        foreach($listings as $key => $listing){
            $arr[]= array(
                "ID #" => $listing->id,	
                "Business Name" => $listing->bname,
                "Date Created" => $listing->date_created,	
                "Buyer Phone" => $listing->buyer_email ? $this->buyer_phone($listing->buyer_email): "",	
                "Buyer Email" => $listing->buyer_email
        );
        }
        return collect($arr);

    }

    public function headings(): array
    {
        return [
            "ID #",	
            "Business Name",
            "Date Created",	
            "Buyer Phone",	
            "Buyer Email"
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

    public function buyer_phone($email){
        $buyer = Buyers::select('phoneno')->where('email', $email)->first();
        if(isset($buyer)) {
            $result = isset($buyer->phoneno)?$buyer->phoneno:'';
        } else {
            $result = "";
        }
        return $result;
    }

}
