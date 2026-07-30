{{-- resources/views/landing/payment-success.blade.php --}}
@extends('layouts.landing')
@section('title', 'Account Activated — WA SaaS Platform')

@section('content')
<section style="padding:80px 0;min-height:calc(100vh - 68px);background:linear-gradient(135deg,#f0fdf9,#e6f7f1);display:flex;align-items:center">
  <div class="container-sm text-center">

    <div style="width:90px;height:90px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:44px;margin:0 auto 28px">🎉</div>

    @if(session('paid'))
    <div class="badge badge-green" style="font-size:14px;padding:6px 18px;margin-bottom:20px">Payment successful</div>
    <h1 style="font-size:clamp(30px,4vw,48px);font-weight:800;color:var(--text);margin-bottom:16px">
      Welcome to WA SaaS!<br><span style="color:var(--brand)">Your account is live.</span>
    </h1>
    <p style="font-size:17px;color:var(--muted);max-width:520px;margin:0 auto 12px;line-height:1.7">
      Payment received and your account for <strong>{{ session('company') }}</strong> is now fully activated.
    </p>
    @elseif(session('registered'))
    <div class="badge badge-brand" style="font-size:14px;padding:6px 18px;margin-bottom:20px">Account created</div>
    <h1 style="font-size:clamp(30px,4vw,48px);font-weight:800;color:var(--text);margin-bottom:16px">
      Welcome, {{ session('company') }}!<br><span style="color:var(--brand)">Your trial is ready.</span>
    </h1>
    <p style="font-size:17px;color:var(--muted);max-width:520px;margin:0 auto 12px;line-height:1.7">
      Your 14-day free trial has started. You have <strong>1,000 free messages</strong> ready to use.
    </p>
    @else
    <h1 style="font-size:clamp(30px,4vw,48px);font-weight:800;color:var(--text);margin-bottom:16px">
      You're all set!<br><span style="color:var(--brand)">Your account is ready.</span>
    </h1>
    @endif

    @if(session('email'))
    <div style="background:#fff;border:1px solid var(--border);border-radius:14px;padding:20px;max-width:400px;margin:24px auto">
      <p style="font-size:13px;color:var(--muted);margin-bottom:6px">Login with</p>
      <p style="font-size:16px;font-weight:700;color:var(--brand)">{{ session('email') }}</p>
    </div>
    @endif

    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin-top:32px">
      <a href="{{ config('landing.app_url','http://localhost:5173') }}/login" class="btn btn-primary btn-lg">
        🚀 Login to your dashboard
      </a>
      <a href="{{ route('whatsapp') }}" target="_blank" class="btn btn-wa btn-lg">
        💬 Get onboarding help
      </a>
    </div>

    {{-- Next steps --}}
    <div style="background:#fff;border:1px solid var(--border);border-radius:18px;padding:32px;max-width:560px;margin:40px auto 0;text-align:left">
      <h3 style="font-size:18px;font-weight:800;margin-bottom:20px;text-align:center">Your next 3 steps</h3>
      @php
      $steps = [
        ['1','Connect your WhatsApp Business API','Go to Settings → WhatsApp credentials → Add your Phone Number ID and Access Token from Meta Business.'],
        ['2','Build your first flow','Go to Flow Builder → Create root node → Add your menu options and connect them to lead categories.'],
        ['3','Launch your first campaign','Go to Campaigns → Create campaign → Select a template → Target your contacts → Launch!'],
      ]
      @endphp
      @foreach($steps as $s)
      <div style="display:flex;gap:16px;align-items:flex-start;padding:14px 0;border-bottom:1px solid var(--border)">
        <div style="width:32px;height:32px;background:var(--brand);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;flex-shrink:0">{{ $s[0] }}</div>
        <div>
          <p style="font-weight:700;font-size:14px;margin-bottom:4px">{{ $s[1] }}</p>
          <p style="font-size:13px;color:var(--muted);line-height:1.6">{{ $s[2] }}</p>
        </div>
      </div>
      @endforeach
    </div>

    <p style="font-size:13px;color:var(--muted);margin-top:32px">
      Need help? Chat with us on <a href="{{ route('whatsapp') }}" style="color:var(--brand);font-weight:600">WhatsApp</a>
      or email <a href="mailto:{{ config('landing.contact_email','hello@waapi.com') }}" style="color:var(--brand);font-weight:600">{{ config('landing.contact_email','hello@waapi.com') }}</a>
    </p>
  </div>
</section>
@endsection
