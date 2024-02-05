<?php

namespace App\Http\Controllers;

use App\CalloutCategory;
use App\Constant\Constants;
use App\Http\Helper\Helper;
use App\Http\Resources\CalloutCategoryResource;
use App\Http\Resources\CalloutResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CalloutCategoryController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $data = [];
        $list = CalloutCategory::latest();
        if ($request->filled('search')) {
            $list->where('title', 'like', "%{$request->search}%");
        }
        if ($request->filled('limit')) {
            $list->take($request->limit);
        }
        $data = $list->get();
        return $this->success(
            [
                'data' => CalloutCategoryResource::collection($data),
                'message' => 'Question Categories List fetched successfully'
            ]
        );
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $auth = Auth::user();
        $request->validate(CalloutCategory::$createRules);
        try {
            $uploadedImage = '';
            if ($request->hasFile('image')) {
                $uploadedImage = Helper::saveImage($request->image, 'callout-category-image');
            }
            $input = [];
            $input['title'] = $request->title;
            $input['slug'] = $this->createSlug(CalloutCategory::class, $request->title);
            $input['image'] = $uploadedImage;

            $category = CalloutCategory::create($input);
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($auth->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $category->id;
                    $data['notifiable_type'] =  get_class($category);
                    $data['created_by_id'] = $auth->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN,
                        Constants::TYPE_SUB_ADMIN
                    ];
                    $data['message'] = "Callout Category create by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_CALLOUT_CATEGORY_CREATED_BY_ADMIN;
                    $data['user_id'] = $category->id;
                    Helper::sendNotification($data);
                }
            return $this->success([
                'data' => new CalloutCategoryResource($category),
                'message' => 'Question Category stored successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param \App\CalloutCategory $calloutCategory
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $model = CalloutCategory::findOrFail($id);
        return $this->success([
            'data' => new CalloutCategoryResource($model),
            'message' => 'Callout Category Fetch Successfully.'
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\CalloutCategory $calloutCategory
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $auth = Auth::user();
        $model = CalloutCategory::findOrFail($request->id);
        $request->validate([
            'title' => 'max:32|string|unique:callout_categories,title,' . $model->id,
            'image' => 'mimes:jpeg,jpg,png,gif' // max 10000kb
        ]);

        try {
            $input = [];
            $oldImage = $model->title;
            $uploadedImage = '';
            if ($request->hasFile('image')) {
                $uploadedImage = Helper::saveImage($request->image, 'callout-category-image');
                Helper::removeImage($oldImage);
            } else {
                $uploadedImage = $oldImage;
            }
            $input['image'] = $uploadedImage;
            if ($request->title != $model->getOriginal('title')) {
                $input['slug'] = $this->createSlug(CalloutCategory::class, $request->title, $model->id);
                $input['title'] = $request->title;
            }
            $model->update($input);
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($auth->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $model->id;
                    $data['notifiable_type'] =  get_class($model);
                    $data['created_by_id'] = $auth->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN,
                        Constants::TYPE_SUB_ADMIN
                    ];
                    $data['message'] = "Question Category updated by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_CALLOUT_CATEGORY_UPDATED_BY_ADMIN;
                    $data['user_id'] = $model->id;
                    Helper::sendNotification($data);
                }
            return $this->success([
                'data' => new CalloutCategoryResource($model),
                'message' => 'Question Category updated successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\CalloutCategory $calloutCategory
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $auth = Auth::user();
        $model = CalloutCategory::findOrFail($request->id);
        $modeldata = CalloutCategory::findOrFail($request->id);
        try {
            $model->delete();
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($auth->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $modeldata->id;
                    $data['notifiable_type'] =  get_class($modeldata);
                    $data['created_by_id'] = $auth->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN,
                        Constants::TYPE_SUB_ADMIN
                    ];
                    $data['message'] = "Callout Category Deleted by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_CALLOUT_CATEGORY_DELETED_BY_ADMIN;
                    $data['user_id'] = $modeldata->id;
                    Helper::sendNotification($data);
                }
            return $this->success('Callout Category deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function posts(Request $request)
    {
        $model = CalloutCategory::findOrFail($request->id);
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $list = $model->callouts()
            ->latest('id')
            ->paginate($limit);
        return $this->success([
            'category' => $model->title,
            'data' => CalloutResource::collection($list),
            'message' => 'Category callouts fetched successfully'
        ]);
    }

    public function postsBySlug(Request $request, $slug)
    {
        $category = CalloutCategory::where('slug', $slug)->first();
        if (!empty($category)) {
            $limit = $request->limit;
            $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
            $list = $category->callouts()
                ->latest('id')
                ->paginate($limit);
            return $this->success([
                'category' => $category->title,
                'data' => CalloutResource::collection($list),
                'message' => 'Category callouts fetched successfully'
            ]);
        }
        return $this->failed('Category not found');
    }
}
