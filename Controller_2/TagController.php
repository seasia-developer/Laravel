<?php

namespace App\Http\Controllers;

use App\Tag;
use App\Http\Resources\TagResource;
use Illuminate\Http\Request;
use App\Constant\Constants;
use App\Http\Helper\Helper;
use App\Http\Resources\IdeaResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TagController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $data = [];
        $list = Tag::select('id', 'title')->withCount('ideas');
        if ($request->has('search')) {
            $list->where('title', 'like', "%{$request->search}%");
        }
        $data = $list->latest('id')->get();
        return $this->success([
            'data' => $data,
            'message' => 'Tags List fetched successfully'
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
        $user = Auth::user();
        $request->validate([
            'title' => 'required|string'
        ]);
        try {
            $request['slug'] = $this->createSlug(Tag::class, $request->title);
            $tag = Tag::create($request->all());
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $tag->id;
                    $data['notifiable_type'] =  get_class($tag);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN,
                        Constants::TYPE_SUB_ADMIN
                    ];
                    $data['message'] = "Tag created by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_TAG_CREATED_BY_ADMIN;
                    $data['user_id'] = Constants::TYPE_ADMIN;
                    Helper::sendNotification($data);
                }
            return $this->success([
                'data' => new TagResource($tag),
                'message' => 'Tag stored successfully'
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
        $tag = Tag::findOrFail($id);
        return $this->success([
            'data' => new TagResource($tag),
            'message' => 'Tag Fetch successfully'
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
        $user = Auth::user();
        $request->validate([
            'title' => 'required|string',
            'id' => 'required'
        ]);
        $tag = Tag::findOrFail($request->id);
        try {
            if ($request->title != $tag->getOriginal('title')) {
                $request['slug'] = $this->createSlug(Tag::class, $request->title, $tag->id);
            }
            $tag->update($request->all());
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $tag->id;
                    $data['notifiable_type'] =  get_class($tag);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN,
                        Constants::TYPE_SUB_ADMIN
                    ];
                    $data['message'] = "Tag updated by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_TAG_UPDATED_BY_ADMIN;
                    $data['user_id'] = Constants::TYPE_ADMIN;
                    Helper::sendNotification($data);
                }
            return $this->success([
                'data' => new TagResource($tag),
                'message' => 'Tag updated successfully'
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
        $user = Auth::user();
        $tag = Tag::findOrFail($request->id);
        $tagModel = Tag::findOrFail($request->id);
        try {
            $tag->delete();
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $tagModel->id;
                    $data['notifiable_type'] =  get_class($tagModel);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN,
                        Constants::TYPE_SUB_ADMIN
                    ];
                    $data['message'] = "Tag deleted by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_TAG_DELETED_BY_ADMIN;
                    $data['user_id'] = Constants::TYPE_ADMIN;
                    Helper::sendNotification($data);
                }
            return $this->success('Tag deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }


    public function popularTags()
    {
        try {
        $tags = DB::table('idea_tag')->select('tag_id',  DB::raw('COUNT(tag_id) AS occurrences'))
            ->groupBy('tag_id')
            ->orderBy('occurrences', 'DESC')
            ->limit(20)
            ->pluck('tag_id');
        $data = Tag::whereIn('id', $tags)
            ->get();
        return $this->success([
            'data' => $data,
            'message' => 'Popular Tags List fetched successfully'
        ]);
       }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function ideas(Request $request)
    {
        $tag = Tag::findOrFail($request->id);
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $list = $tag->ideas()->whereIn('status', [
            Constants::IDEA_APPROVED,
            Constants::IDEA_CLAIMED,
            Constants::IDEA_PUBLISHED
        ])->with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows')
            ->latest('id')
            ->paginate($limit);
        return $this->success([
            'tag' => $tag->title,
            'data' => IdeaResource::collection($list),
            'message' => 'Tag ideas fetched successfully'
        ]);
    }
    public function ideasBySlug(Request $request, $slug)
    {
        $tag = Tag::where('slug', $slug)->first();
        if (!empty($tag)) {
            $limit = $request->limit;
            $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
            $list = $tag->ideas()->whereIn('status', [
                Constants::IDEA_APPROVED,
                Constants::IDEA_CLAIMED,
                Constants::IDEA_PUBLISHED
            ])->with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows')
                ->latest('id')
                ->paginate($limit);
            return $this->success([
                'tag' => $tag->title,
                'data' => IdeaResource::collection($list),
                'message' => 'Tag ideas fetched successfully'
            ]);
        }
        return $this->failed('Tag not found');
    }
}
