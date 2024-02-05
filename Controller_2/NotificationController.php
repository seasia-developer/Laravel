<?php

namespace App\Http\Controllers;

use App\Constant\Constants;
use App\Http\Resources\NotificationResource;
use App\Notification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
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
        $user = Auth::user();
        $list = Notification::where('user_id', $user->id);
        $count = Notification::where('user_id', $user->id);

        $count = $count->where('is_read', Constants::NOTIFICATION_NOT_READ)->count();
        if ($request->filled('month')) {
            $month = Carbon::createFromFormat('m', $request->month);
            $start = $month->startOfMonth()->toDateTimeString();
            $end = $month->endOfMonth()->toDateTimeString();
            $list->whereBetween('created_at', [
                $start,
                $end
            ]);
        }
        $list = $list->latest()->paginate($limit);
        return $this->success(
            [
                'enable_notification' => $user->enable_notification,
                'unread_count' => $count,
                'data' => NotificationResource::collection($list),
                'message' => 'Notification List fetched successfully'
            ]
        );
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
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
        //
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
    public function destroy($id)
    {
        //
    }

    public function read($id)
    {
        $model = Notification::findOrFail($id);
        try {
            $model->update([
                'is_read' => Constants::NOTIFICATION_IS_READ
            ]);
            return $this->success('Notification updated successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
    public function readAll()
    {
        $user = Auth::user()->id;
        try {
            Notification::where('user_id', $user)
                ->where('is_read', Constants::NOTIFICATION_NOT_READ)->update([
                    'is_read' => Constants::NOTIFICATION_IS_READ
                ]);
            return $this->success('Notification updated successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
