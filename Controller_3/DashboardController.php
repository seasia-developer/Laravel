<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;

class DashboardController extends Controller
{
    /** @var \App\Services\AnalyticsService */
    private $analyticsService;

    public function __construct(AnalyticsService $analyticsService) {
        $this->middleware('backend');
        $this->analyticsService = $analyticsService;
    }

    public function showDashboard()
    {
        return view(
            'backend.dashboard',
            [
                'getTodayEarningsOfDigitalProducts'     => $this->analyticsService->getTodayEarningsOfDigitalProducts(),
                'getTodayEarningsOfNonDigitalProducts'  => $this->analyticsService->getTodayEarningsOfNonDigitalProducts(),
                'getTodayEarningsOf100PercentTids'  => $this->analyticsService->getTodayEarningsOf100PercentTids(),
                'getTodayEarningsOfOriginaleTids'  => $this->analyticsService->getTodayEarningsOfOriginaleTids(),
                'getTodayEarningsOfDigitalProductsBelege'     => $this->analyticsService->getTodayEarningsOfDigitalProductsBelege(),
            ]
        );
    }
}
