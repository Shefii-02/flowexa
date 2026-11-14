<?php
namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Models\Plan;

class PlanController extends Controller
{
    public function index()
    {
        $plans = Plan::purchasable()->orderBy('price')->get();
        return view('landing.pricing', compact('plans'));
    }

    public function show(string $slug)
    {
        // slug = plan name lowercased e.g. "growth"
        $plan = Plan::purchasable()
            ->whereRaw('LOWER(name) = ?', [strtolower($slug)])
            ->firstOrFail();

        return view('landing.plan-checkout', compact('plan'));
    }
}
