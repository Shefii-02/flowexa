{{-- resources/views/landing/register.blade.php --}}
@extends('layouts.landing')
@section('title', 'Start Free Trial — WA SaaS Platform')
@section('meta_description', 'Create your WA SaaS account. 14-day free trial. 1,000 free WhatsApp messages. No credit card required.')

@section('content')

<section style="padding:60px 0;min-height:calc(100vh - 68px);background:linear-gradient(135deg,#f0fdf9 0%,#e6f7f1 50%,#f8fafc 100%)">
  <div class="container">
    <div style="max-width:960px;margin:0 auto;display:grid;grid-template-columns:1fr 420px;gap:48px;align-items:start">

      {{-- Left — benefits --}}
      <div style="padding-top:12px">
        <div class="section-label">🚀 Free trial</div>
        <h1 style="font-size:clamp(30px,4vw,46px);font-weight:800;line-height:1.2;margin-bottom:16px">
          Start your<br><span style="color:var(--brand)">14-day free trial</span>
        </h1>
        <p style="font-size:17px;color:var(--muted);line-height:1.7;margin-bottom:32px">
          No credit card required. Get 1,000 free WhatsApp messages instantly. Cancel anytime.
        </p>

        @php
        $benefits = [
          ['✅','1,000 free messages included on signup'],
          ['✅','Full platform access for 14 days'],
          ['✅','Build unlimited WhatsApp flows'],
          ['✅','Add up to 3 staff members on trial'],
          ['✅','OTP API access included'],
          ['✅','No credit card required'],
          ['✅','Setup in under 5 minutes'],
          ['✅','Cancel anytime, no questions asked'],
        ]
        @endphp
        @foreach($benefits as $b)
        <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid rgba(29,158,117,.12);font-size:15px;color:var(--text)">
          <span style="color:var(--brand);font-size:16px">{{ $b[0] }}</span>
          {{ $b[1] }}
        </div>
        @endforeach

        <div style="background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;margin-top:28px;display:flex;gap:16px;align-items:center">
          <div style="font-size:32px">💬</div>
          <div>
            <p style="font-weight:700;font-size:15px">Need help setting up?</p>
            <p style="font-size:13px;color:var(--muted);margin-top:2px">Chat with our team on WhatsApp — we'll get you live in under 30 minutes.</p>
            <a href="{{ route('whatsapp') }}" target="_blank" class="btn btn-wa btn-sm mt-2">Chat now</a>
          </div>
        </div>
      </div>

      {{-- Right — form --}}
      <div class="card" style="padding:36px;border-radius:20px;box-shadow:0 8px 40px rgba(0,0,0,.08)">
        <h2 style="font-size:22px;font-weight:800;margin-bottom:4px">Create your account</h2>
        <p style="font-size:13px;color:var(--muted);margin-bottom:24px">Already have an account? <a href="{{ config('landing.app_url','http://localhost:5173') }}/login" style="color:var(--brand);font-weight:600">Login here</a></p>

        @if($errors->any())
        <div class="alert alert-error">
          @foreach($errors->all() as $e)<div>• {{ $e }}</div>@endforeach
        </div>
        @endif

        <form action="{{ route('register.store') }}" method="POST" id="register-form">
          @csrf

          <div class="form-group">
            <label class="form-label">Company name *</label>
            <input type="text" name="company_name" class="form-control" placeholder="Univexa Technologies Pvt Ltd" value="{{ old('company_name') }}" required>
            @error('company_name')<span class="form-error">{{ $message }}</span>@enderror
          </div>

          <div class="form-group">
            <label class="form-label">Your full name *</label>
            <input type="text" name="name" class="form-control" placeholder="Rahul Menon" value="{{ old('name') }}" required>
            @error('name')<span class="form-error">{{ $message }}</span>@enderror
          </div>

          <div class="form-group">
            <label class="form-label">Work email *</label>
            <input type="email" name="email" class="form-control" placeholder="rahul@univexa.com" value="{{ old('email') }}" required>
            @error('email')<span class="form-error">{{ $message }}</span>@enderror
          </div>

          <div class="form-group">
            <label class="form-label">Phone number *</label>
            <input type="tel" name="phone" class="form-control" placeholder="918086544828" value="{{ old('phone') }}" required>
            <p class="form-hint">Include country code without + (e.g. 918086544828)</p>
            @error('phone')<span class="form-error">{{ $message }}</span>@enderror
          </div>

          <div class="form-group">
            <label class="form-label">Password *</label>
            <input type="password" name="password" class="form-control" placeholder="Min 8 characters" required>
            @error('password')<span class="form-error">{{ $message }}</span>@enderror
          </div>

          <div class="form-group">
            <label class="form-label">Confirm password *</label>
            <input type="password" name="password_confirmation" class="form-control" placeholder="Repeat your password" required>
          </div>

          <div class="form-group">
            <label class="form-label">Interested plan <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
            <select name="plan_id" class="form-control">
              <option value="">Start with 14-day free trial</option>
              @foreach($plans as $plan)
              @if($plan->price > 0)
              <option value="{{ $plan->id }}" {{ old('plan_id') == $plan->id ? 'selected' : '' }}>
                {{ $plan->name }} — ₹{{ number_format($plan->price) }}/month
              </option>
              @endif
              @endforeach
            </select>
            <p class="form-hint">You can upgrade anytime from your dashboard.</p>
          </div>

          <button type="submit" class="btn btn-primary w-full" style="justify-content:center;padding:14px">
            🚀 Create free account
          </button>

          <p style="font-size:11px;color:var(--muted);text-align:center;margin-top:14px;line-height:1.6">
            By signing up, you agree to our Terms of Service and Privacy Policy.
            We will never share your data with third parties.
          </p>
        </form>
      </div>

    </div>
  </div>
</section>

@endsection
