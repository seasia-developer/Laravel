<?php

namespace App\Http\Controllers;

use App\Http\Resources\WebSettingResource;
use App\WebSetting;
use Illuminate\Http\Request;

class WebSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $data = [];
        $list = WebSetting::latest('id');
        if ($request->has('search')) {
            $list->where('title', 'like', "%{$request->search}%");
        }
        $data = $list->get();
        return $this->success([
            'data' => $data,
            'message' => 'Web Setting List fetched successfully'
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'key' => 'required|string|unique:web_settings',
            'value' => 'required|string'
        ]);
        try {
            $model = WebSetting::create($request->all());
            return $this->success([
                'data' => new WebSettingResource($model),
                'message' => 'Web Setting stored successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function storeHeaderScripts(Request $request)
    {
        $request->validate([
            'value' => 'required|string'
        ]);
        try {
            $model = WebSetting::updateOrCreate(
                ['key' => 'header-scripts'],
                ['value' => $request->value]
            );
            return $this->success([
                'data' => new WebSettingResource($model),
                'message' => 'Header Scripts stored successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function viewHeaderScripts()
    {
        try{
                  $model = WebSetting::where('key', 'header-scripts')->first();
            if (!empty($model)) {
            return $this->success([
                'data' => new WebSettingResource($model),
                'message' => 'Web Setting Fetch successfully'
            ]);
        }
        return $this->success();
        }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
  
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $model = WebSetting::findOrFail($id);
        return $this->success([
            'data' => new WebSettingResource($model),
            'message' => 'Web Setting Fetch successfully'
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $model = WebSetting::findOrFail($request->id);
        $request->validate([
            'key' => 'required|string|unique:web_settings,key,' . $model->id,
            'value' => 'required|string',
            'id' => 'required'
        ]);
        try {
            $model->update($request->all());
            return $this->success([
                'data' => new WebSettingResource($model),
                'message' => 'Web Setting updated successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $model = WebSetting::findOrFail($request->id);
        try {
            $model->delete();
            return $this->success('Web Setting deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
