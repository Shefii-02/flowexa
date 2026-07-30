<?php
namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    public function index()
    {
        $plans = Plan::where('is_active', true)->where('is_custom', false)->orderBy('price')->get();
        return view('landing.register', compact('plans'));
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'name'         => ['required', 'string', 'max:100'],
            'email'        => ['required', 'email', 'unique:users,email'],
            'phone'        => ['required', 'string', 'max:20'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
            'plan_id'      => ['nullable', 'exists:plans,id'],
        ]);

        // Create company
        $trialPlan = Plan::where('name', 'Trial')->first()
                  ?? Plan::orderBy('price')->first();

        $company = Company::create([
            'name'         => $d['company_name'],
            'slug'         => Str::slug($d['company_name']) . '-' . Str::random(4),
            'email'        => $d['email'],
            'phone'        => $d['phone'],
            'app_id'       => 'WA_APP_' . strtoupper(Str::random(12)),
            'plan_id'      => $trialPlan?->id,
            'status'       => 'trial',
            'trial_ends_at'=> now()->addDays(14),
            'webhook_verify_token' => Str::random(32),
        ]);

        // Create wallet with 1000 free messages
        Wallet::create([
            'company_id'      => $company->id,
            'balance'         => 1000,
            'total_purchased' => 1000,
            'total_used'      => 0,
        ]);

        // Create owner user
        $ownerRole = Role::where('name', 'owner')->first();
        $user = User::create([
            'company_id' => $company->id,
            'role_id'    => $ownerRole?->id,
            'name'       => $d['name'],
            'email'      => $d['email'],
            'phone'      => $d['phone'],
            'password'   => Hash::make($d['password']),
            'is_active'  => true,
        ]);

        return redirect()->route('payment.success')
            ->with('registered', true)
            ->with('company', $company->name)
            ->with('email', $user->email);
    }
}
