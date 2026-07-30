{{-- resources/views/landing/payment-failed.blade.php --}}
@extends('layouts.landing')
@section('title', 'Payment Failed — WA SaaS Platform')

@section('content')
<section style="padding:80px 0;min-height:calc(100vh - 68px);background:linear-gradient(135deg,#fff5f5,#fef2f2);display:flex;align-items:center">
  <div class="container-sm text-center">

    <div style="width:90px;height:90px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:44px;margin:0 auto 28px">❌</div>

    <div class="badge" style="background:#fee2e2;color:#991b1b;font-size:14px;padding:6px 18px;margin-bottom:20px">Payment failed</div>

    <h1 style="font-size:clamp(28px,4vw,44px);font-weight:800;color:var(--text);margin-bottom:16px">
      Something went wrong<br>with your payment
    </h1>

    <p style="font-size:17px;color:var(--muted);max-width:500px;margin:0 auto 12px;line-height:1.7">
      Don't worry — no amount was charged. Please try again or contact our support team.
    </p>

    @if(session('error'))
    <div style="background:#fff;border:1px solid #fca5a5;border-radius:12px;padding:16px;max-width:480px;margin:20px auto">
      <p style="font-size:13px;color:#dc2626;font-weight:600">Error details:</p>
      <p style="font-size:13px;color:#dc2626;margin-top:4px">{{ session('error') }}</p>
    </div>
    @endif

    <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin-top:32px">
      <a href="javascript:history.back()" class="btn btn-primary btn-lg">
        ← Try again
      </a>
      <a href="{{ route('whatsapp') }}" target="_blank" class="btn btn-wa btn-lg">
        💬 Get help on WhatsApp
      </a>
    </div>

    {{-- Common reasons --}}
    <div style="background:#fff;border:1px solid var(--border);border-radius:18px;padding:28px;max-width:480px;margin:40px auto 0;text-align:left">
      <h3 style="font-size:16px;font-weight:800;margin-bottom:16px">Common reasons for payment failure</h3>
      @php
      $reasons = [
        'Insufficient balance in your card or bank account',
        'Card not enabled for online or international payments',
        'Incorrect OTP entered during bank authentication',
        'Bank temporarily blocked the transaction for security',
        'Session timeout — please refresh and try again',
      ]
      @endphp
      @foreach($reasons as $r)
      <div style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px;color:var(--muted)">
        <span style="color:#dc2626;flex-shrink:0">•</span> {{ $r }}
      </div>
      @endforeach
    </div>

    <div style="margin-top:32px;display:flex;gap:16px;justify-content:center;flex-wrap:wrap">
      <a href="{{ route('pricing') }}" style="font-size:14px;color:var(--brand);font-weight:600">← Back to pricing</a>
      <span style="color:var(--border)">|</span>
      <a href="mailto:{{ config('landing.contact_email','hello@waapi.com') }}" style="font-size:14px;color:var(--brand);font-weight:600">📧 Email support</a>
    </div>
  </div>
</section>
@endsection
