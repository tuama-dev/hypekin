<?php

namespace App\Http\Controllers\Application;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Workspace $workspace)
    {
        return Inertia::render('Application/Dashboard');
    }
}
