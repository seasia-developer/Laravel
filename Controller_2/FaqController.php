<?php

namespace App\Http\Controllers;

use App\Constant\Constants;
use Illuminate\Http\Request;
use App\Faq;
use Carbon\Carbon;
use App\Http\Helper\Helper;
use App\Http\Resources\FaqResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FaqController extends Controller
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
        $user = Auth::guard('api')->user();

        $list = Faq::latest('id');
        $count = $list->count();

        $list = $list->where('status', Constants::STATE_ACTIVE);

        if ($request->filled('search')) {
            $search = $request->search;
            $list = $list->where(function ($query) {
                $query->where('question', 'like', "%$search%")
                ->orWhere('answer', 'like', "%$search%");
                });
        }
        if ($request->filled('status')) {
            $list = $list->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $list = $list->where('user_id', $request->created_by);
        }
        if ($request->filled('month') && $request->filled('year')) {
            $month = Carbon::createFromDate($request->year, $request->month);
            $start = $month->startOfMonth()->toDateTimeString();
            $end = $month->endOfMonth()->toDateTimeString();
            $list = $list->whereBetween('created_at', [
                $start,
                $end
            ]);
        }
        $list->with('createdby');
        $list = $list->paginate($limit);
        return $this->success([
            'allFaqs' => $count,
            'data' => FaqResource::collection($list),
            'message' => 'Faqs List fetched successfully',
        ]);
    }

    public function getList(Request $request)
    {
        
        try {

        $list = Faq::latest('id');
        $count = $list->count();

        $list = $list->where('status', Constants::STATE_ACTIVE);
        $list = $list->get();
        return $this->success([
            'allFaqs' => $count,
            'data' => FaqResource::collection($list),
            'message' => 'Faqs List fetched successfully',
             ]);
            }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
           }

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
     $request->validate([
            'question' => 'required',
            'answer' =>  'required',
            'status' => 'required|numeric'
        ]);
        try {
            DB::beginTransaction();
            $user = Auth::guard('api')->user();
            if($user){
                $data = Faq::create([
                    'question' => $request->question,
                    'answer' => $request->answer,
                    'status' => $request->status,
                    'created_by_id'=> $user->id
                ]);
            
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $data->id;
                    $data['notifiable_type'] =  get_class($data);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN
                    ];
                    $data['message'] = "New FAQ created by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_FAQ_CREATED_BY_ADMIN;
                    $data['user_id'] = $data->id;
                    Helper::sendNotification($data);
                }
             }   
            DB::commit();    
            return $this->success([
                'data' => new FaqResource($data),
                'message' => 'Faq stored successfully.'
            ]);

        } catch (\Illuminate\Database\QueryException $exception) {
            DB::rollback();
            return $this->error($exception->errorInfo);
        } 
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
        $user = Auth::guard('api')->user();
        $request->validate([
            'question' => 'required',
            'answer' =>  'required',
            'status' => 'required|numeric',
            'id' => 'required'
        ]);
        $model = Faq::findOrFail($request->id);
        try {
            $model->update($request->all());
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $model->id;
                    $data['notifiable_type'] =  get_class($model);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN
                    ];
                    $data['message'] = "FAQ updated by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_FAQ_UPDATED_BY_ADMIN;
                    $data['user_id'] = $model->id;
                    Helper::sendNotification($data);
                }
            return $this->success([
                'data' => new FaqResource($model),
                'message' => 'Faq Details updated successfully.'
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
        try {
            $user = Auth::guard('api')->user();
            $model = Faq::findOrFail($request->id);
            $modeldata = Faq::findOrFail($request->id);
            $model->delete();
            $roleIdCheck = array(Constants::TYPE_SUPER_ADMIN, Constants::TYPE_ADMIN, Constants::TYPE_SUB_ADMIN);
                if (in_array($user->role_id, $roleIdCheck)) {
                    $data['notifiable_id'] = $modeldata->id;
                    $data['notifiable_type'] =  get_class($modeldata);
                    $data['created_by_id'] = $user->id;
                    $data['state_id'] = 0;
                    $data['roles'] = [
                        Constants::TYPE_SUPER_ADMIN,
                        Constants::TYPE_ADMIN
                    ];
                    $data['message'] = "FAQ daleted by SuperAdmin|Admin|SubAdmin";
                    $data['type_id'] = Constants::NOTIFICATION_FAQ_DELETED_BY_ADMIN;
                    $data['user_id'] = $modeldata->id;
                    Helper::sendNotification($data);
                }
            return $this->success('Faq Record deleted successfully');
        } catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
    }


    public function getCommonData()
    {

        try
        {
           $list = Faq::select("id","question as title","answer as description","created_at","updated_at")->where("status",Constants::FAQ_COMMON)->first(); 

           if(!empty($list))
           {
            return $this->success([
                'data' =>  $list
            ]);

           }
           else
           {
            $list = array("title"=>"no data","description"=>"no data found");
             return $this->success([
                'data' =>  $list
            ]);
           }
          
        }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }
        

    }


    Public function faqUpdate(Request $request)
    {
        try
        {


        $commonId = $request->id;
        $data['question'] = $request->title;
        $data['answer'] = $request->description;

        Faq::where('status', Constants::FAQ_COMMON)
    ->update($data);

 
   $getFaqCommon = Faq::select("id","question as title","answer as description","created_at","updated_at")->where("status",Constants::FAQ_COMMON)->first(); 

         return $this->success([
                'data' =>  $getFaqCommon,
                'message' => 'Faq Details updated successfully.'
            ]);


   

      }catch (\Illuminate\Database\QueryException $exception) {
            return $this->error($exception->errorInfo);
        }


    }
}
