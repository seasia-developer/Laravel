<?php

namespace App\Http\Controllers;

use App\Constant\Constants;
use App\Http\Helper\Helper;
use App\Http\Resources\PageResource;
use App\Image;
use App\Page;
use App\PageMeta;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PageController extends Controller
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

        $list = Page::where('status', '!=', Constants::IDEA_INACTIVE)
            ->with('images');
        $count = $list->count();
        $publishedCount = Page::where('status', Constants::PAGE_PUBLISHED)->count();
        $pendingReview = Page::where('status', Constants::PAGE_PENDING_REVIEW)->count();

        if ($request->filled('search')) {
            $list->where('title', 'like', "%{$request->search}%")
                ->orWhere('details', "%{$request->search}%");
        }
        if ($request->filled('status')) {
            $list->where('status', $request->status);
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
            'publishedCount' => $publishedCount,
            'pendingReview' => $pendingReview,
            'all' => $count,
            'data' => PageResource::collection($list),
            'message' => 'Page List fetched successfully',
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
            'title' => 'required|max:32|string',
            'details' =>  'required|string',
            'status' => 'required|numeric',
            'images.*' => 'image|mimes:jpeg,jpg,png,gif'
        ]);
        try {
            DB::beginTransaction();
            $user = Auth::guard('api')->user();
            if ($user) {
                $request['user_id'] = $user->id;
                if (!$request->filled('status')) {
                    $request['status'] = Constants::IDEA_PENDING;
                }
            } else {
                $request->validate([
                    'user_id' => 'required'
                ]);
                $request['status'] = Constants::IDEA_INACTIVE;
            }
            $request['slug'] = $this->createSlug(Page::class, $request->title);

            $model = Page::create($request->all());

            if ($request->hasFile('featured_image')) {
                $uploadedImage = Helper::saveImage($request->featured_image, 'page-images', $model->id);
                $image = new Image();
                $image->name = $request->featured_image->getClientOriginalName();
                $image->featured = 1;
                $image->file_name =  $uploadedImage;
                $image->extension = $request->featured_image->getClientOriginalExtension();
                if ($user) {
                    $image->user()->associate($user);
                }
                $model->images()->save($image);
            }

            /* Update page_details_meta table*/
            $pageMeta = pageMeta::firstOrNew(['page_id' =>  $model->id]);
            $pageMeta->page_id = $model->id;
            $pageMeta->meta_title = $request->filled("meta_title") ? $request->meta_title : "";
            $pageMeta->meta_Description = $request->filled("meta_description") ? $request->meta_description : "";
            $pageMeta->save();

            DB::commit();

            return $this->success([
                'data' => new PageResource($model),
                'message' => 'Page stored successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
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
        $model = Page::with('images')->with('meta')->findOrFail($id);
        return $this->success([
            'data' => $model,
            'message' => 'Page Fetch Successfully.'
        ]);
    }
    public function showBySlug($slug)
    {
        $model = Page::with('images')->where('slug', $slug)->where("status",1)->first();

        if (!empty($model)) {

            return $this->success([
                'data' => new PageResource($model),
                'message' => 'Page Fetch Successfully.'
            ]);
        }
        else
        {
           return $this->failed('Page does not Exist');
        }
        
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
        $request->validate([
            'title' => 'required|max:32|string',
            'details' =>  'required|string',
            'status' => 'required|numeric'
        ]);
        $user = Auth::user();
        $model = Page::findOrFail($request->id);

        try {
            DB::beginTransaction();
            $featuredImage = Image::where('imageable_id', $model->id)
                ->where('imageable_type', Page::class)
                ->where('featured', Constants::IS_FEATURED)->first();
            if ($request->filled('remove_image') && ($request->remove_image == 1)) {
                if (!empty($featuredImage)) {
                    Helper::removeImage($featuredImage->file_name);
                    $featuredImage->delete();
                    $featuredImage = null;
                }
            }

            if ($request->hasFile('featured_image')) {
                $request->validate([
                    'featured_image' => 'mimes:jpeg,jpg,png,gif',
                ]);
                $uploadedImage = Helper::saveImage($request->featured_image, 'page-images', $model->id);
                if (!empty($featuredImage)) {
                    Helper::removeImage($featuredImage->file_name);
                    $featuredImage->update([
                        'name' => $request->featured_image,
                        'featured' => Constants::IS_FEATURED,
                        'file_name' => $uploadedImage,
                        'extension' => $request->featured_image->getClientOriginalExtension()
                    ]);
                } else {
                    $image = new Image();
                    $image->name = $request->featured_image->getClientOriginalName();
                    $image->featured = Constants::IS_FEATURED;
                    $image->file_name =  $uploadedImage;
                    $image->extension = $request->featured_image->getClientOriginalExtension();
                    if ($user) {
                        $image->user()->associate($user);
                    }
                    $model->images()->save($image);
                }
            }
            if ($request->title != $model->getOriginal('title')) {
                $request['slug'] = $this->createSlug(Page::class, $request->title, $model->id);
            }
            $model->update($request->all());
            $model = $model->fresh('images');

            /* Update page_meta table*/
            $pageMeta = PageMeta::firstOrNew(['page_id' =>  $model->id]);
            $pageMeta->page_id = $model->id;
            $pageMeta->meta_title = $request->filled("meta_title") ? $request->meta_title : "";
            $pageMeta->meta_Description = $request->filled("meta_description") ? $request->meta_description : "";
            $pageMeta->save();

            DB::commit();

            return $this->success(
                [
                    'data' => new PageResource($model),
                    'message' => 'Page updated successfully.'
                ]
            );
        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
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
        $model = Page::findOrFail($request->id);
        try {
            $model->delete();
            return $this->success('Page deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
