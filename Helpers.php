<?php
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Auth;
use App\Models\Agents;

if (!function_exists('redisFind')) {
    function redisFind($model=null, $select_column=null, $id=null, $prefix=null)
    {
        if ($data = Redis::get($prefix.'_'.$id) ) {
            $data = json_decode($data, FALSE);
            return $data;
        }
        else {
            if(empty($select_column)) {
                $data = $model::find($id);
            } else {
                $data = $model::select($select_column)->findOrFail($id);
            }
            Redis::set($prefix.'_'.$id, $data);
            return $data;
        }
    }
}

if (!function_exists('redisFindOrFail')) {
    function redisFindOrFail($model=null, $select_column=null, $id=null, $prefix=null)
    {
        if ($data = Redis::get($prefix.'_'.$id) ) {
            $data = json_decode($data, FALSE);
            return $data;
        }
        else {
            if(empty($select_column)) {
                $data = $model::findOrFail($id);
            } else {
                $data = $model::select($select_column)->findOrFail($id);
            }
            Redis::set($prefix.'_'.$id, $data);
            return $data;
        }
    }
}


if (!function_exists('redisGetAllRows')) {
    function redisGetAllRows($model=null, $select_column=null, $prefix=null)
    {
        $cached = Redis::get($prefix);
        if(isset($cached)) {
            $data = json_decode($cached, FALSE);
            return $data;
        } else {
            if(empty($select_column)) {
                $data = $model::get();
            } else {
                $data = $model::select($select_column)->get();
            }
            Redis::set($prefix, $data);
            return $data;
        }
    }
}

if (!function_exists('redisGetAllRowsOrderBy')) {
    function redisGetAllRowsOrderBy($model=null, $select_column=null, $prefix=null, $order_by=null)
    {
        $cached = Redis::get($prefix);
        if(isset($cached)) {
            $data = json_decode($cached, FALSE);
            return $data;
        } else {
            if(empty($select_column)) {
                $data = $model::orderBy($order_by)->get();
            } else {
                $data = $model::select($select_column)->orderBy($order_by)->get();
            }
            Redis::set($prefix, $data);
            return $data;
        }
    }
}

if (!function_exists('redisCreate')) {
    function redisCreate($model=null, $input=null, $prefix=null, $delete_from_redis=null)
    {
        $data = $model::create($input);
        if(isset($data)) {
            Redis::set($prefix.'_'.$data->id, $data);
            Redis::del($delete_from_redis);
            return $data;
        }
    }
}

if (!function_exists('redisUpdate')) {
    function redisUpdate($model=null, $input=null, $id=null, $prefix=null, $delete_from_redis=null)
    {
        $update = $model::findOrFail($id)->update($input);
        if(isset($update)) {
            Redis::del($prefix.'_'.$id);
            $data = $model::find($id);
            Redis::set($prefix.'_'.$data->id, $data);
            Redis::del($delete_from_redis);
            return $data;
        }
    }
}

if (!function_exists('redisDelete')) {
    function redisDelete($model=null, $id=null, $prefix=null, $delete_from_redis=null)
    {
        $model::findOrFail($id)->delete();
        Redis::del($prefix.'_'.$id);
        Redis::del($delete_from_redis);
        return 'Deleted';
    }
}

if (!function_exists('redisGetConditionRows')) {
    function redisGetConditionRows($model=null, $select_column=null, $prefix=null, $where=null, $orWhere=null)
    {
        $cached = Redis::get($prefix);
        if(isset($cached)) {
            $data = json_decode($cached, FALSE);
            return $data;
        } else {
            if(empty($select_column)) {
                $data = $model::where($where)->get();
            } 
            elseif(!empty($orWhere)){
                $data = $model::select($select_column)->where($where)->orWhere($orWhere)->get();
            }
            else {
                $data = $model::select($select_column)->where($where)->get();
            }
            Redis::set($prefix, $data);
            return $data;
        }
    }
}

if (!function_exists('redisGetConditionRowsOrderBy')) {
    function redisGetConditionRowsOrderBy($model=null, $select_column=null, $prefix=null, $where=null, $orWhere=null, $order_by=null)
    {
        $cached = Redis::get($prefix);
        if(isset($cached)) {
            $data = json_decode($cached, FALSE);
            return $data;
        } else {
            if(empty($select_column)) {
                $data = $model::where($where)->orderBy($order_by)->get();
            } 
            elseif(!empty($orWhere)){
                $data = $model::select($select_column)->where($where)->orWhere($orWhere)->orderBy($order_by)->get();
            }
            else {
                $data = $model::select($select_column)->where($where)->orderBy($order_by)->get();
            }
            Redis::set($prefix, $data);
            return $data;
        }
    }
}

if (!function_exists('redisUpdateOrCreate')) {
    function redisUpdateOrCreate($model=null, $input_match=null, $input=null, $prefix=null, $delete_from_redis=null)
    {
        $data = $model::updateOrCreate($input_match, $input);
        if(isset($data)) {
            Redis::del($prefix.'_'.$data->id);
            Redis::set($prefix.'_'.$data->id, $data);
            Redis::del($delete_from_redis);
            return $data;
        }
    }
}

if (!function_exists('redisUpdateOrCreateForRetrieve')) {
    function redisUpdateOrCreateForRetrieve($model=null, $input_match=null, $input=null, $prefix=null, $delete_from_redis=null)
    {
        $data = $model::updateOrCreate($input_match, $input);
        if(isset($data)) {
            Redis::del($prefix);
            Redis::set($prefix, $data);
            Redis::del($delete_from_redis);
            return $data;
        }
    }
}

if (!function_exists('redisUpdateForRetrieve')) {
    function redisUpdateForRetrieve($model=null, $input=null, $id=null, $prefix=null, $delete_from_redis=null, $column_name=null)
    {
        $update = $model::firstWhere($column_name, $id)->update($input);
        if(isset($update)) {
            Redis::del($prefix);
            $data = $model::firstWhere($column_name, $id);
            Redis::set($prefix, $data);
            Redis::del($delete_from_redis);
            return $data;
        }
    }
}

if (!function_exists('redisRetrieveFirst')) {
    function redisRetrieveFirst($model=null, $select_column=null, $id=null, $prefix=null, $column_name=null)
    {
        if ($data = Redis::get($prefix) ) {
            $data = json_decode($data, FALSE);
            return $data;
        }
        else {
            if(empty($select_column)) {
                $data = $model::firstWhere($column_name, $id);
            } else {
                $data = $model::select($select_column)->firstWhere($column_name, $id);
            }
            Redis::set($prefix, $data);
            return $data;
        }
    }
}

if (!function_exists('redisRetrieveDelete')) {
    function redisRetrieveDelete($model=null, $where=null, $prefix=null, $delete_from_redis=null)
    {
        $model::where($where)->delete();
        Redis::del($prefix);
        Redis::del($delete_from_redis);
        return 'Deleted';
    }
}

if (!function_exists('redisRetrieveFind')) {
    function redisRetrieveFind($model=null, $select_column=null, $where=null, $prefix=null)
    {
        if ($data = Redis::get($prefix) ) {
            $data = json_decode($data, FALSE);
            return $data;
        }
        else {
            if(empty($select_column)) {
                $data = $model::where($where)->get();
            } else {
                $data = $model::select($select_column)->where($where)->get();
            }
            Redis::set($prefix, $data);
            return $data;
        }
    }
}

if (!function_exists('validEmail')) {
    function validEmail($str=null)
    {
        return (!preg_match("/^([a-z0-9\+_\-]+)(\.[a-z0-9\+_\-]+)*@([a-z0-9\-]+\.)+[a-z]{2,6}$/ix", $str)) ? FALSE : TRUE;
    }
}

if (!function_exists('auth_agent_id')) {
    function auth_agent_id()
    {
        $agent = Agents::select('id', 'user_id')->firstWhere('user_id', Auth::id());
        return $agent->id;
    }
}

if (!function_exists('get_agent_name')) {
    function get_agent_name($id=null)
    {
        $agent = Agents::select('id', 'firstname', 'lastname')->firstWhere('id', $id);
        return $agent->firstname.' '.$agent->lastname;
    }
}

