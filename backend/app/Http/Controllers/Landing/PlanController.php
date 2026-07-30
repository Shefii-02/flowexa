<?php
namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Models\Plan;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::where('is_active', true)->where('is_custom', false)->orderBy('price')->get();
        return view('landing.pricing', compact('plans'));
    }

    public function show(string $slug)
    {
        // slug = plan name lowercased e.g. "growth"
        $plan = Plan::where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [strtolower($slug)])
            ->firstOrFail();

        return view('landing.plan-checkout', compact('plan'));
    }
}
