<?php

namespace App\Http\Controllers;

use App\Constant\Constants;
use App\ContactUs;
use App\Http\Resources\ContactUsResource;
use App\Notifications\ContactUsMail;
use App\User;
use App\Http\Helper\Helper;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ContactUsController extends Controller
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
        $list = ContactUs::latest('id');
        if ($request->filled('search')) {
            $list = $list->where(function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
                $query->orWhere(function ($query) use ($request) {
                    $query->whereHas('user', function ($q) use ($request) {
                        $q->where('name', 'LIKE', "%{$request->search}%");
                    });
                });
            });
        }
        if ($request->filled('status')) {
            $list = $list->where('state_id', $request->status);
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
        $list = $list->paginate($limit);
        return $this->success([
            'data' => ContactUsResource::collection($list),
            'message' => 'ContactUs List fetched successfully',
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
            'name' => "required|string",
            'message' => "required|string",
            'email' => "required|string",
            'subject' => "required|string"
        ]);
        try {
            $model = ContactUs::create($request->all());

            $admins = User::whereIn('role_id', [Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN])->where('state_id', Constants::STATE_ACTIVE)->get();
            if (!empty($admins) && ($admins->count() > 0)) {
                foreach ($admins as $admin) {
                    $this->customSendMail($admin, (new ContactUsMail($request->name, $request->email, $request->message, $request->subject)));
                }
            }

            return $this->success([
                'data' => new ContactUsResource($model),
                'message' => 'Contact Us Details stored successfully.'
            ]);
        } catch (\Illuminate\Database\QueryException $exception) {
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
        try {

        $model = ContactUs::findorFail($id);
        return $this->success([
            'data' => new ContactUsResource($model),
            'message' => 'Contact us Details fetch successfully'
        ]);
      }
      catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
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
            'name' => "required|string",
            'message' => "required|string",
            'email' => "required|string",
            'subject' => "required|string",
            'id' => 'required'
        ]);
        $model = ContactUs::findOrFail($request->id);
        try {
            $model->update($request->all());
            return $this->success([
                'data' => new ContactUsResource($model),
                'message' => 'Contact Us Details updated successfully.'
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
        $model = ContactUs::findOrFail($request->id);
        try {
            $model->delete();
            return $this->success('Contact Record deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }

    public function resolved(Request $request)
    {
        $auth = Auth::user();
        $model = ContactUs::findOrFail($request->id);
        try {
            $msg = [
                0 => 'Contact marked un-resolved successfully',
                1 => 'Contact marked resolved successfully'
            ];
            $model->update([
                'user_id' => Auth::user()->id,
                'state_id' => $request->state_id
            ]);
            if ($model->state_id == Constants::STATE_ACTIVE) {
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
                    $data['message'] = "Contact marked resolved by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_CONTACT_RESOLVED_BY_ADMIN;
                    $data['user_id'] = $model->id;
                    Helper::sendNotification($data);
                }
                return $this->success('Contact marked resolved successfully'); 
            } elseif ($model->state_id == Constants::STATE_DEACTIVATE) {
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
                    $data['message'] = "Contact marked un-resolved by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_CONTACT_UNRESOLVED_BY_ADMIN;
                    $data['user_id'] = $model->id;
                    Helper::sendNotification($data);
                }
                return $this->success('Contact marked un-resolved successfully');
            } else {
                return $this->failed('Invalid status!!!');
            }
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }
}
