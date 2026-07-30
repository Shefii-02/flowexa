<?php
namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Models\Plan;

class HomeController extends Controller
{
    public function index()
    {
        $plans = Plan::where('is_active', true)->where('is_custom', false)->orderBy('price')->get();
        return view('landing.home', compact('plans'));
    }

    public function features()
    {
        return view('landing.features');
    }
}
