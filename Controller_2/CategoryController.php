<?php

namespace App\Http\Controllers;

use App\Categories;
use App\Constant\Constants;
use App\Http\Helper\Helper;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\IdeaResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $data = [];
        $uncategorised = [
            'id' => 0,
            'category' => "Uncategorized",
            'category_image' => "/uncategorised.jpeg",
            'category_icon' => "/uncategorised_icon.png",
            'slug' => "uncategorized"
        ];
        $list = Categories::withCount('ideas');
        if ($request->filled('search')) {
            $data = $list->where('category', 'like', "%{$request->search}%")
                ->withCount('ideas')->take(Constants::PAGINATION_COUNT)->latest()->get();

            return $this->success(
            [
                'data' => $data,
                'message' => 'Categories List fetched successfully'
            ]
        );
        }
        if ($request->filled('limit')) {
            $list->take($request->limit);
        }
        $data = $list->where('category','!=', 'Miscellaneous')->orderBy('category','ASC')->latest()->get();
        if (Categories::where('category', 'Miscellaneous')->exists()) {
           $data->push(Categories::where('category', 'Miscellaneous')->withCount('ideas')->first());
        }

        // array_push($data, $uncategorised);

        return $this->success(
            [
                'data' => $data,
                'message' => 'Categories List fetched successfully'
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
        $user = Auth::user();
        $request->validate(Categories::$createRules);
        try {
            $uploadedImage = '';
            $uploadedIcon = '';
            if ($request->hasFile('image')) {
                $uploadedImage = Helper::saveImage($request->image, 'category-image');
            }
            if ($request->hasFile('icon')) {
                $uploadedIcon = Helper::saveImage($request->icon, 'category-image');
            }

            $request['slug'] = $this->createSlug(Categories::class, $request->category);
            $request['category_image'] = $uploadedImage;
            $request['category_icon'] =  $uploadedIcon;

            $category = Categories::create($request->all());

            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
            if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $category->id;
                    $data['notifiable_type'] =  get_class($category);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN
                    ];
                    $data['message'] = "category created by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_CATEGORY_CREATED_BY_ADMIN;
                    $data['user_id'] = Constants::TYPE_ADMIN;
                    Helper::sendNotification($data);
                }
            return $this->success([
                'data' => new CategoryResource($category),
                'message' => 'Category stored successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Category $category
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $category = Categories::findOrFail($id);
        return $this->success([
            'data' => new CategoryResource($category),
            'message' => 'Category Fetch Successfully.'
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Category $category
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $user = Auth::user();
        $categoryModel = Categories::findOrFail($request->id);
        $request->validate([
            'category' => 'max:32|string|unique:categories,category,' . $categoryModel->id,
            'image' => 'mimes:jpeg,jpg,png,gif',
            'icon' => 'mimes:jpeg,jpg,png,gif',
        ]);

        try {
            $oldImage = $categoryModel->category_image;
            $oldIcon = $categoryModel->icon;
            $uploadedImage = '';
            $uploadedIcon = '';
            if ($request->hasFile('image')) {
                $uploadedImage = Helper::saveImage($request->image, 'category-image');
                Helper::removeImage($oldImage);
                $request['category_image'] =  $uploadedImage;
            }
            if ($request->hasFile('icon')) {
                $uploadedIcon = Helper::saveImage($request->icon, 'category-image');
                Helper::removeImage($oldIcon);
                $request['category_icon'] = $uploadedIcon;
            }
            if ($request->category != $categoryModel->getOriginal('category')) {
                $request['slug'] = $this->createSlug(Categories::class, $request->category, $categoryModel->id);
            }
            if ($categoryModel->update($request->all())) {
                $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $categoryModel->id;
                    $data['notifiable_type'] =  get_class($categoryModel);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN
                    ];
                    $data['message'] = "category update by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_CATEGORY_UPDATED_BY_ADMIN;
                    $data['user_id'] = Constants::TYPE_ADMIN;
                    Helper::sendNotification($data);
                }
                return $this->success([
                    'data' => new CategoryResource($categoryModel),
                    'message' => 'Category updated successfully.'
                ]);
            } else {
                return $this->failed([
                    'message' => 'Category update failed.'
                ]);
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Category $category
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request)
    {
        $user = Auth::user();
        $category = Categories::findOrFail($request->id);
        $categoryModel = Categories::findOrFail($request->id);
        try {
            $category->delete();
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $request->id;
                    $data['notifiable_type'] =  get_class($categoryModel);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN
                    ];
                    $data['message'] = "category deleted by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_CATEGORY_DELETED_BY_ADMIN;
                    $data['user_id'] = Constants::TYPE_ADMIN;
                    Helper::sendNotification($data);
                }
            return $this->success('Category deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function ideas(Request $request)
    {
        $category = Categories::findOrFail($request->id);
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $list = $category->ideas()->whereIn('status', [
            Constants::IDEA_APPROVED,
            Constants::IDEA_CLAIMED,
            Constants::IDEA_PUBLISHED
        ])->with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows')
            ->latest('id')
            ->paginate($limit);
        return $this->success([
            'category' => $category->category,
            'data' => IdeaResource::collection($list),
            'message' => 'Category ideas fetched successfully'
        ]);
    }
    public function ideasBySlug(Request $request, $slug)
    {
        $category = Categories::where('slug', $slug)->first();
        if (!empty($category)) {
            $limit = $request->limit;
            $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
            $list = $category->ideas()->whereIn('status', [
                Constants::IDEA_APPROVED,
                Constants::IDEA_CLAIMED,
                Constants::IDEA_PUBLISHED
            ])->with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows')
                ->latest('id')
                ->paginate($limit);
            return $this->success([
                'category' => $category->category,
                'data' => IdeaResource::collection($list),
                'message' => 'Category ideas fetched successfully'
            ]);
        }
        return $this->failed('Category not found');
    }
}
