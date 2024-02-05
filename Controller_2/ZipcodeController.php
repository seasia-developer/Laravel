<?php

namespace App\Http\Controllers;

use App\Zipcode;
use App\Constant\Constants;
use App\Http\Resources\ZipcodeResource;
use Illuminate\Http\Request;

class ZipcodeController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;

        if ($request->has('search')) {
            $list = Zipcode::select('id', 'zipcode')->where('zipcode', 'like', "%{$request->search}%")
                ->withCount('ideas')
                ->latest()
                ->paginate($limit);
        } else {
            $list = Zipcode::select('id', 'zipcode')->withCount('ideas')
                ->latest()
                ->paginate($limit);
        }

        $list->appends([
            'key' => 'value'
        ]);
        return $this->success([
            'data' => $list,
            'message' => 'Zipcode List fetched successfully'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $this->validate($request, Zipcode::$createRules);
        try {
            $zipcode = Zipcode::create($request->all());
            return $this->success([
                'data' => new ZipcodeResource($zipcode),
                'message' => 'Zipcode stored successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $zipcode = Zipcode::findOrFail($id);
        return $this->success([
            'data' => new ZipcodeResource($zipcode),
            'message' => 'Zipcode Fetch Successfully.'
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $request->validate([
            'zipcode' => 'required|max:5|string',
            'id' => 'required'
        ]);
        $zipcode = Zipcode::findOrFail($request->id);

        try {
            $zipcode->update($request->all());
            return $this->success([
                'data' => new ZipcodeResource($zipcode),
                'message' => 'Zipcode updated successfully'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $zipcode = Zipcode::findOrFail($request->id);
        try {
            $zipcode->delete();
            return $this->success('Zipcode deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
