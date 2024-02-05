<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Vendors;
use Illuminate\Support\Facades\Validator;

class VendorsController extends Controller
{
    public function all(Request $request)
    {
        try {
            $model = new Vendors(); 
            $prefix = 'vendors';
            $select_column = ['id', 'vendor_name'];

            $vendors = redisGetAllRows($model, $select_column, $prefix);

            return response()->json(['message'=>'success','code'=>'200','data'=>$vendors]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function index(Request $request)
    {
        try {
            if(isset($request->per_page) && $request->per_page <= 25) {
                $per_page = $request->per_page;
            } else {
                $per_page = 10;
            }

            $query = Vendors::select('id', 'vendor_name');

            if(isset($request->search) && !empty($request->search)){
                $searchFields = ['vendor_name'];
                $query->where(function($query) use($request, $searchFields){
                  $searchWildcard = '%' . $request->search . '%';
                  foreach($searchFields as $field){
                    $query->orWhere($field, 'LIKE', $searchWildcard);
                  }
                });
            }

            $vendors = $query->paginate($per_page);

            return response()->json(['message'=>'success','code'=>'200','data'=>$vendors]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function store(Request $request)
    {
        try {
            $input = $request->except(['id']);

            $id = isset($request->id)?$request->id:null;

            $validator = Validator::make($input, [
                'vendor_name' => 'required',
                'vendor_address' => 'required',
                'vendor_phone' => 'required',
                'vendor_email' => 'required',
            ]);
     
            if ($validator->fails()) {
                return response()->json(['message'=>'error','code'=>'302','data'=>$validator->errors()]);
            }

            $model = new Vendors();
            $prefix = 'vendor';
            $delete_from_redis = 'vendors';

            $input_match = ['id'   => $id];

            $vendors = redisUpdateOrCreate($model, $input_match, $input, $prefix, $delete_from_redis);

            return response()->json(['message'=>'success','code'=>'200','data'=>$vendors]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function show($id)
    {
        try {
            $model = new Vendors();
            $prefix = 'vendor';
            $select_column = null;

            $vendor = redisFind($model, $select_column, $id, $prefix);

            return response()->json(['message'=>'success','code'=>'200','data'=>$vendor]);

        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }

    public function delete(Request $request, $id)
    {
        try {
            $model = new Vendors();
            $prefix = 'vendor';
            $delete_from_redis = 'vendors';
            $vendor = redisDelete($model, $id, $prefix, $delete_from_redis);
            
            return response()->json(['message'=>'success','code'=>'200','data'=>'Selected vendor removed successfully.']);
        
        } catch (\Exception $e) {
            return response()->json(['message'=>'error','code'=>'302','data'=>$e->getMessage()]);
        }
    }
    
}
