<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\pageDetailsMeta;
use Illuminate\Support\Facades\DB;

class PageDetailsMetaController extends Controller
{
    public function getMetaByPage($page)
    {
        try {
            DB::beginTransaction();
            $data = [];
            $data = pageDetailsMeta::where('page', $page)->first();
            DB::commit();

            return $this->success([
                'data' => $data,
                'message' => 'Page Details Meta fetched successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        }
    }
}
