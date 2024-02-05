<?php

namespace App\Http\Controllers;

use App\Callout;
use App\Constant\Constants;
use App\Http\Resources\ImageResource;
use App\Http\Resources\ImagesResource;
use App\Idea;
use App\IdeaImages;
use App\Image;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
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
        $list = Image::with('user');
        if ($request->filled('type')) {
            $type = $request->type;
            if ($type == Constants::IMAGE_IDEA) {
                $list = $list->where('imageable_type', Idea::class);
            }
            if ($type == Constants::IMAGE_CALLOUT) {
                $list = $list->where('imageable_type', Callout::class);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            if ($request->filled('type')) {
                $type = $request->type;
                if ($type == Constants::IMAGE_IDEA) {
                    $list = $list->where(function ($query) use ($search) {
                        $query->whereHasMorph(
                            'imageable',
                            ['App\Idea'],
                            function ($q) use ($search) {
                                $q->where('title', 'LIKE', '%' . $search . '%');
                            }
                        );
                    });
                }
                if ($type == Constants::IMAGE_CALLOUT) {
                    $list = $list->where(function ($query) use ($search) {
                        $query->whereHasMorph(
                            'imageable',
                            ['App\Callout'],
                            function ($q) use ($search) {
                                $q->where('title', 'LIKE', '%' . $search . '%');
                            }
                        );
                    });
                }
                if ($type == Constants::IMAGE_ALL) {
                    $list = $list->where(function ($query) use ($search) {
                        $query->whereHasMorph(
                            'imageable',
                            ['App\Callout', 'App\Idea'],
                            function ($q) use ($search) {
                                $q->where('title', 'LIKE', '%' . $search . '%');
                            }
                        );
                    });
                }
            }
        }

        if ($request->filled('created_by')) {
            $searchUser = $request->created_by;
            $list = $list->where(function ($query) use ($searchUser) {
                $query->whereHas('user', function ($q) use ($searchUser) {
                    $q->where('name', 'LIKE', '%' . $searchUser . '%');
                });
            });
        }

        if ($request->filled('month') && $request->filled('year')) {
            $month = Carbon::createFromDate($request->year, $request->month);
            $start = $month->startOfMonth()->toDateTimeString();
            $end = $month->endOfMonth()->toDateTimeString();
            $list->whereBetween('created_at', [
                $start,
                $end
            ]);
        }
        $list = $list->latest('id')->paginate($limit);
        return $this->success([
            'data' => ImagesResource::collection($list),
            'message' => 'Images List fetched successfully',
        ]);
    }
    public function paginate($items, $perPage)
    {
        $pageStart = LengthAwarePaginator::resolveCurrentPage();

        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;

        //  Get only the items you need using array_slice
        // if items is collection
        $itemsForCurrentPage = $items->slice($offSet, $perPage)->values()->all();

        // if items is array
        // $itemsForCurrentPage = array_slice($items, $offSet, $perPage, false);
        return new LengthAwarePaginator(
            $itemsForCurrentPage,
            count($items),
            $perPage,
            Paginator::resolveCurrentPage(),
            array('path' => Paginator::resolveCurrentPath())
        );
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
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $model = Image::findOrFail($id);
        return $this->success([
            'data' => new ImageResource($model),
            'message' => 'Images List fetched successfully',
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
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $model = Image::findOrFail($request->id);
        try {
            Storage::disk('public')->delete($model->file_name);
            $model->delete();
            return $this->success('File deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
