<?php

namespace App\Http\Controllers;

use App\Callout;
use App\Constant\Constants;
use App\Http\Resources\CalloutResource;
use App\Http\Resources\IdeaResource;
use App\Idea;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchController extends Controller
{
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
    public function searchFrontend(Request $request)
    {

        $request->validate([
            'search' => 'required|string'
        ]);
        $data = [];
        $limit = $request->limit;
        $limit = ($limit) ? $limit : Constants::PAGINATION_COUNT;
        $idea = Idea::where('is_blocked', '!=', Constants::BLOCKED)->with('categories', 'createdBy', 'claimedBy', 'tags', 'images', 'follows')
            ->where(function ($q) use ($request) {
                $q->orwhere('title', 'like', "%{$request->search}%")
                    ->orWhere('details', 'like', "%{$request->search}%")
                    ->orWhere('zipcode', $request->search);
            })
            ->whereIn('status', [Constants::IDEA_PUBLISHED, Constants::IDEA_APPROVED, Constants::IDEA_CLAIMED,Constants::IDEA_PENDING,Constants::IDEA_DUPLICATE,Constants::IDEA_DRAFT]);
        $ideaCount = $idea->count();
        $idea  = $idea->latest('id')->get();
        // print '<pre>';
        // print_r($idea);
        // exit;

        $callout = Callout::where('status','!=',Constants::STATE_DEACTIVATE)->where('title', 'like', "%{$request->search}%");
        $calloutCount = $callout->count();
        $callout = $callout->latest('id')->get();

        $idea = IdeaResource::collection($idea);
        $callout = CalloutResource::collection($callout);

        $data = $idea->toBase()->merge($callout->toBase());
        $data = $this->paginate($data, $limit);
        return $this->success([
            'allIdeas' => $ideaCount,
            'allCallouts' => $calloutCount,
            'data' => $data,
            'message' => 'Search results List fetched successfully',
        ]);
    }
}
