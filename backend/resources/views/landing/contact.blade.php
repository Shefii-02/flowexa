{{-- resources/views/landing/contact.blade.php --}}
@extends('layouts.landing')
@section('title', 'Contact Us — WA SaaS Platform')
@section('meta_description', 'Get in touch with our team. We reply within 24 hours. Chat on WhatsApp for instant support.')

@section('content')

<section style="padding:60px 0 40px;background:linear-gradient(135deg,#f0fdf9,#e6f7f1)">
  <div class="container text-center">
    <div class="section-label">📬 Contact</div>
    <h1 class="section-title" style="font-size:clamp(32px,5vw,52px)">We'd love to hear from you</h1>
    <p class="section-sub" style="margin:12px auto 0">Sales enquiry, technical support, or just want a demo — reach out and we'll reply within 24 hours.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="grid-2" style="gap:48px;align-items:start">

      {{-- Contact form --}}
      <div class="card" style="padding:36px">
        <h2 style="font-size:22px;font-weight:800;margin-bottom:6px">Send us a message</h2>
        <p class="text-muted text-sm mb-6">Fill the form or chat on WhatsApp for instant replies.</p>

        @if(session('success'))
        <div class="alert alert-success">✅ {{ session('success') }}</div>
        @endif

        @if($errors->any())
        <div class="alert alert-error">
          <ul style="list-style:none;padding:0;margin:0">
            @foreach($errors->all() as $e)
            <li>• {{ $e }}</li>
            @endforeach
          </ul>
        </div>
        @endif

        <form action="{{ route('contact.send') }}" method="POST">
          @csrf
          <div class="grid-2" style="gap:16px">
            <div class="form-group">
              <label class="form-label">Full name *</label>
              <input type="text" name="name" class="form-control" placeholder="Rahul Menon" value="{{ old('name') }}" required>
              @error('name')<span class="form-error">{{ $message }}</span>@enderror
            </div>
            <div class="form-group">
              <label class="form-label">Email address *</label>
              <input type="email" name="email" class="form-control" placeholder="rahul@company.com" value="{{ old('email') }}" required>
              @error('email')<span class="form-error">{{ $message }}</span>@enderror
            </div>
            <div class="form-group">
              <label class="form-label">Phone number</label>
              <input type="tel" name="phone" class="form-control" placeholder="+91 98765 43210" value="{{ old('phone') }}">
            </div>
            <div class="form-group">
              <label class="form-label">Company name</label>
              <input type="text" name="company" class="form-control" placeholder="Univexa Technologies" value="{{ old('company') }}">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Subject *</label>
            <select name="subject" class="form-control" required>
              <option value="">Select a subject...</option>
              <option value="Sales enquiry — pricing and plans"       {{ old('subject')==='Sales enquiry — pricing and plans'       ? 'selected':'' }}>Sales enquiry — pricing and plans</option>
              <option value="Request a live demo"                     {{ old('subject')==='Request a live demo'                     ? 'selected':'' }}>Request a live demo</option>
              <option value="Technical support"                       {{ old('subject')==='Technical support'                       ? 'selected':'' }}>Technical support</option>
              <option value="API / OTP integration help"             {{ old('subject')==='API / OTP integration help'             ? 'selected':'' }}>API / OTP integration help</option>
              <option value="WhatsApp Business API setup"            {{ old('subject')==='WhatsApp Business API setup'            ? 'selected':'' }}>WhatsApp Business API setup</option>
              <option value="Billing and payment issue"              {{ old('subject')==='Billing and payment issue'              ? 'selected':'' }}>Billing and payment issue</option>
              <option value="Partnership / reseller enquiry"         {{ old('subject')==='Partnership / reseller enquiry'         ? 'selected':'' }}>Partnership / reseller enquiry</option>
              <option value="Other"                                   {{ old('subject')==='Other'                                   ? 'selected':'' }}>Other</option>
            </select>
            @error('subject')<span class="form-error">{{ $message }}</span>@enderror
          </div>

          <div class="form-group">
            <label class="form-label">Message *</label>
            <textarea name="message" class="form-control" rows="5" placeholder="Tell us about your requirements — team size, WhatsApp use case, any specific features you need..." required>{{ old('message') }}</textarea>
            @error('message')<span class="form-error">{{ $message }}</span>@enderror
          </div>

          <div style="display:flex;gap:12px;flex-wrap:wrap">
            <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">
              📧 Send message
            </button>
            <button type="submit" name="via_whatsapp" value="1" class="btn btn-wa" style="flex:1;justify-content:center">
              💬 Send via WhatsApp
            </button>
          </div>

          <p class="text-xs text-muted text-center mt-3">We reply within 24 hours on business days (Mon–Sat, 9 AM–6 PM IST)</p>
        </form>
      </div>

      {{-- Contact info --}}
      <div>
        {{-- WhatsApp CTA --}}
        <div style="background:linear-gradient(135deg,#25D366,#1da851);border-radius:18px;padding:28px;margin-bottom:24px;color:#fff">
          <div style="font-size:32px;margin-bottom:12px">💬</div>
          <h3 style="font-size:20px;font-weight:800;margin-bottom:8px">Chat on WhatsApp</h3>
          <p style="font-size:14px;opacity:.9;margin-bottom:20px">Get instant answers from our team. We respond within minutes during business hours.</p>
          <a href="{{ route('whatsapp') }}" target="_blank" class="btn btn-white" style="width:100%;justify-content:center">
            💬 Start WhatsApp chat
          </a>
        </div>

        {{-- Info cards --}}
        @php
        $contacts = [
          ['📧','Email us','hello@waapi.com','mailto:'.config('landing.contact_email','hello@waapi.com'),'For sales, billing, and general enquiries'],
          ['📞','Call us','+91 80865 44828','tel:+918086544828','Mon–Sat · 9 AM – 6 PM IST'],
          ['📍','Visit us',config('landing.address','Kochi, Kerala, India'),'#','We welcome in-person demos by appointment'],
        ]
        @endphp
        @foreach($contacts as $c)
        <div class="card" style="display:flex;gap:16px;align-items:flex-start;margin-bottom:14px;padding:20px">
          <div style="width:44px;height:44px;background:var(--brand-light);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">{{ $c[0] }}</div>
          <div>
            <p style="font-size:13px;font-weight:700;color:var(--muted)">{{ $c[1] }}</p>
            <a href="{{ $c[3] }}" style="font-size:15px;font-weight:700;color:var(--text);display:block;margin:2px 0">{{ $c[2] }}</a>
            <p style="font-size:12px;color:var(--muted)">{{ $c[4] }}</p>
          </div>
        </div>
        @endforeach

        {{-- Response time --}}
        <div style="background:var(--brand-light);border:1px solid #a7f3d0;border-radius:14px;padding:20px;margin-top:8px">
          <p style="font-size:13px;font-weight:700;color:var(--brand);margin-bottom:10px">⚡ Typical response times</p>
          @php
          $times = [['WhatsApp chat','Under 30 minutes'],['Email','Within 24 hours'],['Phone','Immediate (business hours)'],['Demo request','Within 2 hours']]
          @endphp
          @foreach($times as $t)
          <div style="display:flex;justify-content:space-between;font-size:13px;padding:6px 0;border-bottom:1px solid rgba(29,158,117,.15)">
            <span style="color:var(--text)">{{ $t[0] }}</span>
            <span style="color:var(--brand);font-weight:600">{{ $t[1] }}</span>
          </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>
</section>

{{-- Requirements section --}}
<section class="section section-alt">
  <div class="container">
    <div class="text-center mb-12">
      <div class="section-label">📋 Requirements</div>
      <h2 class="section-title">What you need to get started</h2>
      <p class="section-sub" style="margin:12px auto 0">Here's what to prepare before your onboarding call so we can get you live faster.</p>
    </div>
    <div class="grid-3">
      @php
      $reqs = [
        ['📱','WhatsApp Business API Access','You need a verified Meta Business account with WhatsApp Business API access. We guide you through the Meta setup if you don\'t have one yet. Required: Phone Number ID, Access Token, Business Account ID.','Required'],
        ['👤','Company Details','Company name, registered email address, phone number, and primary contact person. This creates your owner account and company profile in our platform.','Required'],
        ['💳','Payment Method','Razorpay-supported payment: credit/debit card, UPI, net banking, or wallet. Used for plan subscription and message credit top-ups.','Required for paid plans'],
        ['🏢','Team Structure','Optional but useful: how many staff members will use the platform, their roles (admin/counsellor/viewer), and your lead assignment workflow.','Optional'],
        ['📋','Existing Contacts','If you have existing WhatsApp contacts to import, prepare a CSV with columns: phone, name, email. We support bulk import of up to 25,000 contacts per batch.','Optional'],
        ['🌿','Flow Requirements','Think through your WhatsApp chatbot script — what options do you want to offer customers, which replies should create leads, and what categories to use.','Optional'],
      ]
      @endphp
      @foreach($reqs as $r)
      <div class="card" style="position:relative">
        <div style="position:absolute;top:16px;right:16px">
          <span class="badge {{ $r[3]==='Required' ? 'badge-brand' : ($r[3]==='Required for paid plans' ? 'badge-blue' : 'badge-green') }}">
            {{ $r[3] }}
          </span>
        </div>
        <div class="feature-icon">{{ $r[0] }}</div>
        <div class="feature-title" style="padding-right:80px">{{ $r[1] }}</div>
        <div class="feature-desc mt-2">{{ $r[2] }}</div>
      </div>
      @endforeach
    </div>

    <div style="background:#fff;border:1px solid var(--border);border-radius:16px;padding:28px;margin-top:32px;display:flex;align-items:center;gap:24px;flex-wrap:wrap">
      <div style="flex:1;min-width:260px">
        <h3 style="font-size:18px;font-weight:800;margin-bottom:8px">Not sure where to start?</h3>
        <p style="font-size:14px;color:var(--muted)">Book a free 30-minute onboarding call. Our team will walk you through Meta Business API setup, platform configuration, and your first flow — all live.</p>
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <a href="{{ route('whatsapp') }}" target="_blank" class="btn btn-wa">💬 WhatsApp us to book</a>
        <a href="{{ route('register') }}" class="btn btn-primary">Start free trial</a>
      </div>
    </div>
  </div>
</section>

@endsection
