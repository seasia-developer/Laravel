<?php

namespace App\Http\Controllers;

use App\Constant\Constants;
use App\User;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $data = [];

        return $this->success([
            'data' => $data
        ]);
    }

    public function MonthlySignups(Request $request)
    {
        $request->validate([
            'role' => 'required'
        ]);
        $date = new \DateTime();
        $date->modify('-12  months');
        $count = array();
        $data = array();
        $xaxis = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
        for ($i = 1; $i <= 12; $i++) {
            $date->modify('+1 months');
            $count[] = User::whereYear('created_at', '=', date('Y'))
                ->whereMonth('created_at', $i)
                ->where('role_id', Constants::TYPE_COMMUNITY_USER)
                ->count();
        }
        $data['xaxis'] = $xaxis;
        $data['count'] = $count;
        return $this->success(
            [
                'data' => $data,
                'message' => 'Graph data fetched'
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
}
