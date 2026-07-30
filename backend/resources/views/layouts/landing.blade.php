<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'WA SaaS — WhatsApp Business Platform')</title>
<meta name="description" content="@yield('meta_description', 'Automate WhatsApp messaging, manage leads, run campaigns, and grow your business with WA SaaS Platform.')">

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ── Reset & base ── */
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Inter',system-ui,sans-serif;color:#1e293b;background:#fff;line-height:1.6;font-size:15px}
a{text-decoration:none;color:inherit}
img{max-width:100%;display:block}

/* ── Variables ── */
:root{
  --brand:#1D9E75;--brand-dark:#157a5a;--brand-light:#e6f7f1;
  --text:#1e293b;--muted:#64748b;--border:#e2e8f0;--bg:#f8fafc;
  --radius:12px;--shadow:0 4px 24px rgba(0,0,0,.08);
}

/* ── Utilities ── */
.container{max-width:1160px;margin:0 auto;padding:0 24px}
.container-sm{max-width:780px;margin:0 auto;padding:0 24px}
.flex{display:flex}.items-center{align-items:center}.justify-between{justify-content:space-between}.gap-2{gap:8px}.gap-3{gap:12px}.gap-4{gap:16px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:24px}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
.text-center{text-align:center}
.mt-2{margin-top:8px}.mt-3{margin-top:12px}.mt-4{margin-top:16px}.mt-6{margin-top:24px}.mt-8{margin-top:32px}.mt-12{margin-top:48px}
.mb-2{margin-bottom:8px}.mb-4{margin-bottom:16px}.mb-6{margin-bottom:24px}
.hidden{display:none}
.w-full{width:100%}
.font-bold{font-weight:700}.font-semibold{font-weight:600}
.text-sm{font-size:13px}.text-xs{font-size:12px}
.text-muted{color:var(--muted)}
.text-brand{color:var(--brand)}
.rounded{border-radius:var(--radius)}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:10px;font-weight:600;font-size:14px;cursor:pointer;border:none;transition:all .2s;white-space:nowrap}
.btn-primary{background:var(--brand);color:#fff}.btn-primary:hover{background:var(--brand-dark);transform:translateY(-1px)}
.btn-outline{background:transparent;color:var(--brand);border:2px solid var(--brand)}.btn-outline:hover{background:var(--brand-light)}
.btn-white{background:#fff;color:var(--brand)}.btn-white:hover{background:#f0fdf9}
.btn-dark{background:#1e293b;color:#fff}.btn-dark:hover{background:#0f172a}
.btn-lg{padding:15px 32px;font-size:16px;border-radius:12px}
.btn-sm{padding:8px 16px;font-size:13px;border-radius:8px}
.btn-wa{background:#25D366;color:#fff;gap:8px}.btn-wa:hover{background:#1da851}

/* ── Forms ── */
.form-group{margin-bottom:18px}
.form-label{display:block;font-size:13px;font-weight:600;color:var(--text);margin-bottom:6px}
.form-control{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;color:var(--text);background:#fff;outline:none;transition:border .2s}
.form-control:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(29,158,117,.1)}
.form-control::placeholder{color:#94a3b8}
select.form-control{cursor:pointer}
.form-hint{font-size:12px;color:var(--muted);margin-top:4px}
.form-error{font-size:12px;color:#dc2626;margin-top:4px}

/* ── Cards ── */
.card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:24px}
.card-hover:hover{box-shadow:var(--shadow);transform:translateY(-2px);transition:all .2s}

/* ── Badges ── */
.badge{display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:600;padding:4px 10px;border-radius:20px}
.badge-brand{background:var(--brand-light);color:var(--brand)}
.badge-green{background:#dcfce7;color:#16a34a}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-popular{background:var(--brand);color:#fff}

/* ── Alert ── */
.alert{padding:14px 18px;border-radius:10px;font-size:14px;margin-bottom:16px}
.alert-success{background:#f0fdf4;border:1px solid #86efac;color:#16a34a}
.alert-error{background:#fef2f2;border:1px solid #fca5a5;color:#dc2626}

/* ── Navbar ── */
.navbar{position:sticky;top:0;z-index:100;background:rgba(255,255,255,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
.navbar-inner{height:68px;display:flex;align-items:center;justify-content:space-between}
.navbar-logo{display:flex;align-items:center;gap:10px}
.navbar-logo-icon{width:36px;height:36px;background:var(--brand);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px}
.navbar-logo-text{font-size:18px;font-weight:800;color:var(--text)}
.navbar-logo-text span{color:var(--brand)}
.nav-links{display:flex;align-items:center;gap:8px}
.nav-link{padding:8px 14px;border-radius:8px;font-size:14px;font-weight:500;color:var(--muted);transition:all .15s}
.nav-link:hover{color:var(--brand);background:var(--brand-light)}
.nav-link.active{color:var(--brand)}

/* ── Hero ── */
.hero{padding:90px 0 80px;background:linear-gradient(135deg,#f0fdf9 0%,#e6f7f1 40%,#f8fafc 100%)}
.hero-badge{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--border);border-radius:20px;padding:6px 14px;font-size:13px;font-weight:500;color:var(--muted);margin-bottom:20px}
.hero-badge span{color:var(--brand);font-weight:600}
.hero h1{font-size:clamp(36px,5vw,60px);font-weight:800;line-height:1.15;color:var(--text);letter-spacing:-1px}
.hero h1 mark{background:linear-gradient(135deg,var(--brand),#2ecc9a);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{font-size:18px;color:var(--muted);max-width:600px;margin-top:16px;line-height:1.7}
.hero-ctas{display:flex;align-items:center;gap:14px;margin-top:32px;flex-wrap:wrap}
.hero-stats{display:flex;gap:40px;margin-top:48px;flex-wrap:wrap}
.hero-stat p{font-size:28px;font-weight:800;color:var(--text)}
.hero-stat span{font-size:13px;color:var(--muted);display:block;margin-top:2px}

/* ── Sections ── */
.section{padding:80px 0}
.section-alt{background:var(--bg)}
.section-label{display:inline-flex;align-items:center;gap:6px;background:var(--brand-light);color:var(--brand);font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px;letter-spacing:.05em;text-transform:uppercase;margin-bottom:14px}
.section-title{font-size:clamp(26px,4vw,40px);font-weight:800;color:var(--text);line-height:1.2;letter-spacing:-.5px}
.section-sub{font-size:17px;color:var(--muted);margin-top:12px;max-width:600px;line-height:1.7}

/* ── Feature cards ── */
.feature-icon{width:52px;height:52px;background:var(--brand-light);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;margin-bottom:16px;flex-shrink:0}
.feature-title{font-size:17px;font-weight:700;color:var(--text);margin-bottom:8px}
.feature-desc{font-size:14px;color:var(--muted);line-height:1.6}

/* ── Pricing cards ── */
.pricing-card{border:2px solid var(--border);border-radius:18px;padding:32px;position:relative;transition:all .2s}
.pricing-card:hover{box-shadow:var(--shadow)}
.pricing-card.popular{border-color:var(--brand)}
.popular-badge{position:absolute;top:-14px;left:50%;transform:translateX(-50%);background:var(--brand);color:#fff;font-size:12px;font-weight:700;padding:4px 16px;border-radius:20px;white-space:nowrap}
.pricing-name{font-size:20px;font-weight:800;color:var(--text)}
.pricing-price{font-size:40px;font-weight:800;color:var(--text);margin:16px 0 4px;line-height:1}
.pricing-price sup{font-size:18px;font-weight:600;vertical-align:top;margin-top:6px}
.pricing-price span{font-size:14px;font-weight:400;color:var(--muted)}
.pricing-desc{font-size:14px;color:var(--muted);margin-bottom:24px;min-height:40px}
.pricing-features{list-style:none;space-y:10px}
.pricing-features li{display:flex;align-items:flex-start;gap:8px;font-size:14px;color:var(--text);padding:5px 0;border-bottom:1px solid var(--bg)}
.pricing-features li:last-child{border-bottom:none}
.pricing-features li::before{content:"✓";color:var(--brand);font-weight:700;flex-shrink:0;margin-top:1px}
.pricing-features li.dim{color:var(--muted)}
.pricing-features li.dim::before{content:"—";color:#cbd5e1}

/* ── FAQ ── */
.faq-item{border:1px solid var(--border);border-radius:12px;margin-bottom:10px;overflow:hidden}
.faq-q{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;cursor:pointer;font-weight:600;font-size:15px}
.faq-q:hover{background:var(--bg)}
.faq-a{padding:0 20px;max-height:0;overflow:hidden;transition:all .3s;font-size:14px;color:var(--muted);line-height:1.7}
.faq-a.open{max-height:300px;padding:0 20px 18px}
.faq-icon{font-size:18px;transition:transform .3s;flex-shrink:0}
.faq-icon.open{transform:rotate(45deg)}

/* ── Footer ── */
.footer{background:#0f172a;color:#94a3b8;padding:60px 0 30px}
.footer-logo{color:#fff;font-size:20px;font-weight:800;margin-bottom:12px}
.footer-logo span{color:var(--brand)}
.footer-desc{font-size:13px;line-height:1.7;max-width:280px}
.footer-heading{font-size:13px;font-weight:700;color:#e2e8f0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:16px}
.footer-links{list-style:none}
.footer-links li{margin-bottom:10px}
.footer-links a{font-size:13px;color:#94a3b8;transition:color .15s}
.footer-links a:hover{color:#fff}
.footer-bottom{border-top:1px solid #1e293b;margin-top:48px;padding-top:24px;display:flex;justify-content:space-between;align-items:center;font-size:13px;flex-wrap:gap}

/* ── WhatsApp float button ── */
.wa-float{position:fixed;bottom:28px;right:28px;z-index:999;background:#25D366;color:#fff;width:60px;height:60px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:26px;box-shadow:0 6px 24px rgba(37,211,102,.4);transition:all .2s;cursor:pointer}
.wa-float:hover{transform:scale(1.1);box-shadow:0 8px 32px rgba(37,211,102,.5)}
.wa-float-tooltip{position:absolute;right:70px;background:#1e293b;color:#fff;font-size:12px;padding:6px 12px;border-radius:8px;white-space:nowrap;opacity:0;pointer-events:none;transition:opacity .2s}
.wa-float:hover .wa-float-tooltip{opacity:1}

/* ── Checkout page ── */
.checkout-wrap{max-width:960px;margin:0 auto;padding:40px 24px;display:grid;grid-template-columns:1fr 420px;gap:32px;align-items:start}
.checkout-form-box{background:#fff;border:1px solid var(--border);border-radius:18px;padding:32px}
.checkout-summary{background:var(--bg);border:1px solid var(--border);border-radius:18px;padding:28px;position:sticky;top:90px}
.checkout-plan-name{font-size:22px;font-weight:800;color:var(--text);margin-bottom:4px}
.checkout-price{font-size:38px;font-weight:800;color:var(--brand);margin:12px 0}

/* ── Duration tabs ── */
.duration-tabs{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
.dur-tab{padding:9px 18px;border:1.5px solid var(--border);border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;background:#fff;transition:all .15s;position:relative}
.dur-tab:hover{border-color:var(--brand)}
.dur-tab.active{border-color:var(--brand);background:var(--brand-light);color:var(--brand)}
.dur-tab .save-tag{position:absolute;top:-8px;right:-4px;background:#16a34a;color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:8px}

/* ── Steps ── */
.steps{display:flex;gap:0;margin-bottom:32px;counter-reset:step}
.step-item{flex:1;text-align:center;position:relative}
.step-item::after{content:'';position:absolute;top:20px;left:50%;width:100%;height:2px;background:var(--border);z-index:0}
.step-item:last-child::after{display:none}
.step-dot{width:40px;height:40px;border-radius:50%;background:var(--border);color:var(--muted);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;margin:0 auto 8px;position:relative;z-index:1;transition:all .2s}
.step-item.active .step-dot{background:var(--brand);color:#fff}
.step-item.done .step-dot{background:#16a34a;color:#fff}
.step-label{font-size:12px;font-weight:600;color:var(--muted)}
.step-item.active .step-label{color:var(--brand)}

/* ── Testimonials ── */
.testimonial-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:28px}
.testimonial-stars{color:#f59e0b;font-size:16px;margin-bottom:12px;letter-spacing:2px}
.testimonial-text{font-size:15px;color:var(--text);line-height:1.7;margin-bottom:16px;font-style:italic}
.testimonial-author{display:flex;align-items:center;gap:12px}
.testimonial-avatar{width:44px;height:44px;border-radius:50%;background:var(--brand-light);display:flex;align-items:center;justify-content:center;font-size:18px}
.testimonial-name{font-size:14px;font-weight:700;color:var(--text)}
.testimonial-role{font-size:12px;color:var(--muted)}

/* ── Responsive ── */
@media(max-width:768px){
  .grid-2,.grid-3,.grid-4{grid-template-columns:1fr}
  .checkout-wrap{grid-template-columns:1fr}
  .checkout-summary{position:static}
  .hero-stats{gap:24px}
  .nav-links .btn{display:none}
  .hero{padding:60px 0 50px}
  .section{padding:56px 0}
}
</style>

@stack('head')
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <div class="container navbar-inner">
    <a href="{{ route('home') }}" class="navbar-logo">
      <div class="navbar-logo-icon">💬</div>
      <div class="navbar-logo-text">WA<span>SaaS</span></div>
    </a>
    <div class="nav-links">
      <a href="{{ route('home') }}"    class="nav-link {{ request()->routeIs('home')     ? 'active' : '' }}">Home</a>
      <a href="{{ route('features') }}" class="nav-link {{ request()->routeIs('features') ? 'active' : '' }}">Features</a>
      <a href="{{ route('pricing') }}"  class="nav-link {{ request()->routeIs('pricing')  ? 'active' : '' }}">Pricing</a>
      <a href="{{ route('contact') }}"  class="nav-link {{ request()->routeIs('contact')  ? 'active' : '' }}">Contact</a>
      <a href="{{ config('landing.app_url', 'http://localhost:5173') }}/login" class="btn btn-outline btn-sm">Login</a>
      <a href="{{ route('register') }}" class="btn btn-primary btn-sm">Start free trial</a>
    </div>
  </div>
</nav>

<!-- Page content -->
@yield('content')

<!-- WhatsApp float button -->
<a href="{{ route('whatsapp') }}" target="_blank" class="wa-float" title="Chat on WhatsApp">
  <span class="wa-float-tooltip">Chat with us on WhatsApp</span>
  💬
</a>

<!-- Footer -->
<footer class="footer">
  <div class="container">
    <div class="grid-4" style="gap:40px">
      <div>
        <div class="footer-logo">WA<span>SaaS</span></div>
        <p class="footer-desc">The complete WhatsApp Business Platform for growing companies. Automate, engage, and convert.</p>
        <div style="display:flex;gap:12px;margin-top:20px">
          <a href="{{ route('whatsapp') }}" class="btn btn-wa btn-sm">💬 WhatsApp Us</a>
        </div>
      </div>
      <div>
        <p class="footer-heading">Product</p>
        <ul class="footer-links">
          <li><a href="{{ route('features') }}">Features</a></li>
          <li><a href="{{ route('pricing') }}">Pricing</a></li>
          <li><a href="{{ route('register') }}">Free trial</a></li>
          <li><a href="{{ config('landing.app_url') }}/login">Login</a></li>
        </ul>
      </div>
      <div>
        <p class="footer-heading">Features</p>
        <ul class="footer-links">
          <li><a href="{{ route('features') }}#flow">Flow builder</a></li>
          <li><a href="{{ route('features') }}#campaigns">Campaigns</a></li>
          <li><a href="{{ route('features') }}#leads">Lead management</a></li>
          <li><a href="{{ route('features') }}#otp">OTP service</a></li>
          <li><a href="{{ route('features') }}#analytics">Analytics</a></li>
        </ul>
      </div>
      <div>
        <p class="footer-heading">Contact</p>
        <ul class="footer-links">
          <li><a href="{{ route('contact') }}">Contact us</a></li>
          <li><a href="{{ route('whatsapp') }}">WhatsApp chat</a></li>
          <li><a href="mailto:{{ config('landing.contact_email','hello@waapi.com') }}">{{ config('landing.contact_email','hello@waapi.com') }}</a></li>
        </ul>
        <p class="footer-heading mt-6">Address</p>
        <p style="font-size:13px;line-height:1.7">{{ config('landing.address','Kochi, Kerala, India') }}</p>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© {{ date('Y') }} WA SaaS Platform. All rights reserved.</p>
      <p>Built with ❤️ in Kerala, India</p>
    </div>
  </div>
</footer>

<!-- FAQ toggle script -->
<script>
document.querySelectorAll('.faq-q').forEach(q => {
  q.addEventListener('click', () => {
    const a    = q.nextElementSibling
    const icon = q.querySelector('.faq-icon')
    const open = a.classList.contains('open')
    document.querySelectorAll('.faq-a.open').forEach(el => {
      el.classList.remove('open')
      el.previousElementSibling.querySelector('.faq-icon').classList.remove('open')
    })
    if (!open) { a.classList.add('open'); icon.classList.add('open') }
  })
})
</script>

@stack('scripts')
</body>
</html>
