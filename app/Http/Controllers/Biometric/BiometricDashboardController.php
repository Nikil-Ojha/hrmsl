<?php

namespace App\Http\Controllers\Biometric;

use App\Biometric\Services\DashboardService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BiometricDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('hr/biometric-dashboard/index', $this->dashboard->build($request));
    }
}
