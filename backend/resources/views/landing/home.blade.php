{{-- resources/views/landing/home.blade.php --}}
@extends('layouts.landing')

@section('title', 'WA SaaS — WhatsApp Business Platform for Growing Teams')
@section('meta_description', 'Automate WhatsApp messaging, manage leads, run bulk campaigns, build chatbot flows and grow your business. 14-day free trial. No credit card required.')

@section('content')

{{-- ── HERO ── --}}
<section class="hero">
  <div class="container">
    <div class="hero-badge">🚀 <span>New:</span> Meta Ads integration now live</div>
    <h1>
      The Complete<br>
      <mark>WhatsApp Business</mark><br>
      Platform for Teams
    </h1>
    <p class="hero-sub">
      Automate conversations, run bulk campaigns, manage leads, send OTPs
      and track everything — all from one powerful dashboard.
    </p>
    <div class="hero-ctas">
      <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
        Start free trial — 14 days free
      </a>
      <a href="{{ route('whatsapp') }}" target="_blank" class="btn btn-wa btn-lg">
        💬 Chat with us
      </a>
    </div>
    <div class="hero-stats">
      <div class="hero-stat"><p>1,000</p><span>Free messages on signup</span></div>
      <div class="hero-stat"><p>5</p><span>WhatsApp numbers per account</span></div>
      <div class="hero-stat"><p>83+</p><span>API endpoints</span></div>
      <div class="hero-stat"><p>5</p><span>Languages supported</span></div>
    </div>
  </div>
</section>

{{-- ── LOGOS / TRUST ── --}}
<section style="padding:32px 0;border-bottom:1px solid var(--border)">
  <div class="container text-center">
    <p class="text-sm text-muted mb-4">Trusted by growing businesses across India</p>
    <div class="flex items-center" style="justify-content:center;gap:48px;flex-wrap:wrap;opacity:.5;filter:grayscale(1)">
      <span style="font-size:22px;font-weight:800">UNIVEXA</span>
      <span style="font-size:22px;font-weight:800">BRIGHTWAY</span>
      <span style="font-size:22px;font-weight:800">NEXGEN</span>
      <span style="font-size:22px;font-weight:800">EDUFOCUS</span>
      <span style="font-size:22px;font-weight:800">REALTORS+</span>
    </div>
  </div>
</section>

{{-- ── FEATURES GRID ── --}}
<section class="section" id="features">
  <div class="container">
    <div class="text-center mb-12">
      <div class="section-label">✨ Everything you need</div>
      <h2 class="section-title">One platform, every WhatsApp tool</h2>
      <p class="section-sub" style="margin:12px auto 0">From chatbot flows to bulk campaigns, lead management to OTP — all built-in.</p>
    </div>
    <div class="grid-3">
      @php
      $features = [
        ['🌿','Flow Builder','Build WhatsApp chatbot trees with buttons and lists. Auto-assign leads when customers reply. Max 5 levels deep, circular-reference protected.'],
        ['📢','Bulk Campaigns','Send WhatsApp messages to thousands of contacts. Target by labels or CSV upload. Throttle control, pause/resume, delivery stats.'],
        ['🎯','Lead Pipeline','Kanban + table view. Auto-create leads from WhatsApp flows. Assign to counsellors, track stages, round-robin distribution.'],
        ['🔑','OTP Service','Send OTPs via WhatsApp using your own credentials. Device-ID binding, 15-min expiry, rate limiting. Works with any app.'],
        ['📊','Advanced Analytics','Cohort analysis, staff performance comparison, best send-time analysis, wallet burn rate, lead scoring.'],
        ['📱','Multi Phone Numbers','Connect up to 5 WhatsApp Business numbers per company. Set a default, verify connections, per-campaign number selection.'],
        ['💬','WA Templates','Create and manage WhatsApp message templates. Sync approval status from Meta. Reusable in campaigns and flows.'],
        ['💰','Wallet & Billing','Prepaid message credits. Razorpay integration. Plan upgrades, monthly/yearly billing, topup packages, auto-debit on campaigns.'],
        ['🚫','Blacklist & Dedup','Block numbers from all messages. 24-hour deduplication — same number gets max 1 message per day per company automatically.'],
        ['🔔','Push Notifications','Firebase FCM alerts for new leads, lead assignments, campaign completions, and low wallet balance.'],
        ['📣','Meta Ads Manager','Connect your Meta ad account. Create campaigns, ad sets with 20+ audience templates, image/video/carousel creatives.'],
        ['🌐','Multi-language','Full UI in English, Malayalam, Hindi, Tamil, and Arabic (RTL). User language preference saved per account.'],
      ]
      @endphp
      @foreach($features as $f)
      <div class="card card-hover">
        <div class="feature-icon">{{ $f[0] }}</div>
        <div class="feature-title">{{ $f[1] }}</div>
        <div class="feature-desc">{{ $f[2] }}</div>
      </div>
      @endforeach
    </div>
    <div class="text-center mt-8">
      <a href="{{ route('features') }}" class="btn btn-outline">See all features →</a>
    </div>
  </div>
</section>

{{-- ── HOW IT WORKS ── --}}
<section class="section section-alt">
  <div class="container">
    <div class="text-center mb-12">
      <div class="section-label">⚡ How it works</div>
      <h2 class="section-title">Up and running in minutes</h2>
    </div>
    <div class="grid-4">
      @php
      $steps = [
        ['1','Sign up','Create your account. Get 1,000 free messages instantly. No credit card needed.'],
        ['2','Connect WhatsApp','Add your WhatsApp Business phone number ID and access token from Meta.'],
        ['3','Build your flow','Create a chatbot tree. When customers reply, leads are auto-created and assigned.'],
        ['4','Launch & grow','Run campaigns, track analytics, manage leads — all from your dashboard.'],
      ]
      @endphp
      @foreach($steps as $s)
      <div class="text-center">
        <div style="width:56px;height:56px;background:var(--brand);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;margin:0 auto 16px">{{ $s[0] }}</div>
        <h3 style="font-size:17px;font-weight:700;margin-bottom:8px">{{ $s[1] }}</h3>
        <p class="feature-desc">{{ $s[2] }}</p>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ── PRICING PREVIEW ── --}}
<section class="section" id="pricing">
  <div class="container">
    <div class="text-center mb-12">
      <div class="section-label">💰 Simple pricing</div>
      <h2 class="section-title">Plans for every stage of growth</h2>
      <p class="section-sub" style="margin:12px auto 0">Start free. Scale as you grow. Save up to 20% on yearly plans.</p>
    </div>
    <div class="grid-4" style="align-items:start">
      @foreach($plans as $plan)
      @php
        $popular = strtolower($plan->name) === 'growth';
        $features_list = is_array($plan->features) ? $plan->features : [];
        $limits = [
          $plan->messages_limit ? number_format($plan->messages_limit).' messages' : 'Unlimited messages',
          ($plan->max_users ?? '∞').' users',
          ($plan->max_phone_numbers ?? 1).' phone number'.($plan->max_phone_numbers > 1 ? 's' : ''),
          ($plan->max_campaigns ? $plan->max_campaigns.' campaigns' : 'Unlimited campaigns'),
          ($plan->max_contacts ? number_format($plan->max_contacts).' contacts' : 'Unlimited contacts'),
          $plan->throttle_per_minute.' msgs/min',
        ];
      @endphp
      <div class="pricing-card {{ $popular ? 'popular' : '' }}">
        @if($popular)<div class="popular-badge">⭐ Most Popular</div>@endif
        <div class="pricing-name">{{ $plan->name }}</div>
        <div class="pricing-desc">{{ $plan->name === 'Trial' ? 'Perfect for testing and exploring the platform.' : ($plan->name === 'Starter' ? 'Great for small teams just getting started.' : ($plan->name === 'Growth' ? 'For growing businesses with active sales teams.' : 'For large enterprises with custom needs.')) }}</div>
        <div class="pricing-price"><sup>₹</sup>{{ number_format($plan->price) }}<span>/month</span></div>
        <a href="{{ $plan->price == 0 ? route('register') : route('plans.show', strtolower($plan->name)) }}"
           class="btn {{ $popular ? 'btn-primary' : 'btn-outline' }} w-full text-center mt-4 mb-6" style="justify-content:center">
          {{ $plan->price == 0 ? 'Start free trial' : 'Get started' }}
        </a>
        <ul class="pricing-features">
          @foreach($limits as $l)
          <li>{{ $l }}</li>
          @endforeach
          @foreach($features_list as $f)
          <li>{{ $f }}</li>
          @endforeach
        </ul>
      </div>
      @endforeach
    </div>
    <div class="text-center mt-8">
      <a href="{{ route('pricing') }}" class="btn btn-dark">Compare all plans in detail →</a>
    </div>
  </div>
</section>

{{-- ── TESTIMONIALS ── --}}
<section class="section section-alt">
  <div class="container">
    <div class="text-center mb-12">
      <div class="section-label">❤️ Loved by teams</div>
      <h2 class="section-title">What our customers say</h2>
    </div>
    <div class="grid-3">
      @php
      $testimonials = [
        ['⭐⭐⭐⭐⭐','"WA SaaS transformed our lead management. We went from losing track of WhatsApp enquiries to a fully automated pipeline. Our counsellors now focus only on hot leads."','Rahul Menon','Founder, Univexa Technologies','👨‍💼'],
        ['⭐⭐⭐⭐⭐','"The flow builder is incredible. We set it up once and now every WhatsApp inquiry is automatically categorized, a lead is created, and the right counsellor is notified instantly."','Priya Sharma','Operations Head, Brightway Academy','👩‍💼'],
        ['⭐⭐⭐⭐⭐','"The OTP API saved us weeks of development. We integrated WhatsApp OTP into our app in under 2 hours. The 24-hour dedup feature prevents spam complaints automatically."','Arun Kumar','CTO, NexGen Software','👨‍💻'],
      ]
      @endphp
      @foreach($testimonials as $t)
      <div class="testimonial-card">
        <div class="testimonial-stars">{{ $t[0] }}</div>
        <p class="testimonial-text">{{ $t[1] }}</p>
        <div class="testimonial-author">
          <div class="testimonial-avatar">{{ $t[4] }}</div>
          <div>
            <div class="testimonial-name">{{ $t[2] }}</div>
            <div class="testimonial-role">{{ $t[3] }}</div>
          </div>
        </div>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ── FAQ ── --}}
<section class="section" id="faq">
  <div class="container-sm">
    <div class="text-center mb-12">
      <div class="section-label">❓ FAQ</div>
      <h2 class="section-title">Frequently asked questions</h2>
    </div>
    @php
    $faqs = [
      ['Do I need a Meta/WhatsApp Business API account?','Yes. You need a verified Meta Business account with WhatsApp Business API access. You bring your own phone number ID and access token. We guide you through the setup.'],
      ['Is there a free trial?','Yes! Every new account gets a 14-day free trial with 1,000 free WhatsApp messages. No credit card required to start.'],
      ['Can I have multiple WhatsApp numbers?','Yes. Depending on your plan you can connect 1–5 WhatsApp Business phone numbers. Each can be used for different campaigns or departments.'],
      ['How does billing work?','We use a prepaid wallet system for messages. You buy message credits (from ₹29 for 100 messages to ₹1,199 for 10,000). Plan subscriptions are separate and billed monthly or yearly via Razorpay.'],
      ['Is my data secure?','Yes. Each company\'s data is fully isolated in a multi-tenant architecture. No company can access another\'s data. All tokens are encrypted and webhook connections are verified.'],
      ['Can I use this for OTP verification in my app?','Yes. The OTP service has a dedicated API with X-App-Id and X-Private-Token authentication. Integrate WhatsApp OTP into any app with 2 API calls.'],
      ['What is the Meta Ads module?','It allows you to create and manage Facebook and Instagram ad campaigns directly from our platform. Your Meta ad spend goes to your own Meta ad account — it is completely separate from your WA message wallet.'],
      ['Do you support multiple languages?','Yes. The platform UI is available in English, Malayalam, Hindi, Tamil, and Arabic (with full RTL support). Each user can set their own language preference.'],
    ]
    @endphp
    @foreach($faqs as $faq)
    <div class="faq-item">
      <div class="faq-q">
        <span>{{ $faq[0] }}</span>
        <span class="faq-icon">+</span>
      </div>
      <div class="faq-a">{{ $faq[1] }}</div>
    </div>
    @endforeach
  </div>
</section>

{{-- ── CTA BANNER ── --}}
<section style="padding:80px 0;background:linear-gradient(135deg,#1D9E75,#157a5a)">
  <div class="container text-center">
    <h2 style="font-size:clamp(28px,4vw,44px);font-weight:800;color:#fff;margin-bottom:16px">
      Ready to automate your WhatsApp business?
    </h2>
    <p style="font-size:18px;color:rgba(255,255,255,.8);margin-bottom:32px">
      Join hundreds of companies using WA SaaS to convert WhatsApp enquiries into customers.
    </p>
    <div class="flex items-center" style="justify-content:center;gap:16px;flex-wrap:wrap">
      <a href="{{ route('register') }}" class="btn btn-white btn-lg">
        Start your free 14-day trial
      </a>
      <a href="{{ route('whatsapp') }}" target="_blank" class="btn btn-lg" style="background:rgba(255,255,255,.15);color:#fff;border:2px solid rgba(255,255,255,.3)">
        💬 Talk to sales
      </a>
    </div>
    <p style="font-size:13px;color:rgba(255,255,255,.6);margin-top:16px">No credit card required · 1,000 free messages · Setup in minutes</p>
  </div>
</section>

@endsection
