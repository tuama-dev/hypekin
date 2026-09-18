<?php

namespace App\Http\Controllers\Application\Workspace;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        return inertia('workspace/dashboard');
    }
}
