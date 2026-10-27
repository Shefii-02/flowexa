{{-- resources/views/landing/home.blade.php — standalone single-page home --}}
@php
  $appUrl  = rtrim(config('landing.app_url'), '/');
  $partner = config('landing.meta_partner_label');
  $faqs = [
    ['Do I need my own WhatsApp Business API access?', 'Teamzo connects to the WhatsApp Business Platform through Meta. You bring a verified Meta Business account and a phone number; we walk you through the connection and keep your tokens encrypted.'],
    ['How are messages billed?', 'Plans cover the platform. Messages run on a prepaid wallet, debited as campaigns and conversations send. Meta ad spend is billed by Meta to your own ad account and never touches the wallet.'],
    ['Can several teammates work one number?', 'Yes. Conversations are assigned to people or round-robined across a team, with notes, labels and a full history on every contact.'],
    ['Is each company’s data separated?', 'Every company runs in an isolated tenant. Staff only see their own company’s contacts, chats and campaigns.'],
    ['Is there a free trial?', 'Every new account starts with a 14-day trial and 1,000 free messages. No card is needed to begin.'],
  ];
  $caps = [
    ['Shared inbox', 'One conversation list for the whole team — assignment, labels, internal notes and quick replies, on every connected number.'],
    ['Bulk campaigns', 'Approved templates sent to labelled segments or uploaded lists, with throttling, pause and resume, and per-message delivery status.'],
    ['Flow builder', 'Button and list-driven automations that qualify an enquiry, create the lead and route it to the right person.'],
    ['Lead pipeline', 'Kanban and table views, stages, counsellor assignment, and a trail from first message to close.'],
    ['OTP & transactional API', 'Send one-time codes and alerts over WhatsApp with scoped credentials, rate limits and expiry built in.'],
    ['Click-to-WhatsApp ads', 'Connect a Meta ad account, build audiences and creatives, and see which ads actually start conversations.'],
  ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teamzo — Customer conversations on WhatsApp, run as a team</title>
<meta name="description" content="Teamzo is a WhatsApp Business platform for teams: shared inbox, bulk campaigns, automation flows and a lead pipeline, built on Meta’s WhatsApp Business Platform.">
<link rel="icon" href="/teamzo-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{--paper:#f5f2ea;--paper-2:#ebe7db;--ink:#14231c;--ink-2:#46544d;--rule:#cfc9b8;--green:#0f5a40;--green-d:#0a3f2d;--wa:#d9f2dc;--accent:#c4501b}
@media (prefers-color-scheme:dark){:root{--paper:#101a15;--paper-2:#17241d;--ink:#ece8dc;--ink-2:#a6b0a8;--rule:#2b3a31;--green:#5fcf9f;--green-d:#8be0b9;--wa:#1c3a2c;--accent:#f08a55}}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--paper);color:var(--ink);font:16px/1.6 'IBM Plex Sans',system-ui,sans-serif;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.wrap{max-width:1120px;margin:0 auto;padding:0 24px}
.serif{font-family:'Fraunces',Georgia,serif;font-weight:400;letter-spacing:-.02em}
.mono{font-family:'IBM Plex Mono',monospace;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-2)}
.btn{display:inline-block;padding:13px 22px;border:1.5px solid var(--ink);font-weight:500;font-size:15px;transition:.15s;border-radius:2px}
.btn.fill{background:var(--ink);color:var(--paper)}
.btn.fill:hover{background:var(--green);border-color:var(--green);color:#fff}
.btn.line:hover{background:var(--ink);color:var(--paper)}
header{border-bottom:1px solid var(--rule)}
.nav{display:flex;align-items:center;justify-content:space-between;height:68px}
.brand{display:flex;align-items:center;gap:10px;font:600 22px 'Fraunces',serif}
.brand img{width:28px;height:28px;border-radius:7px}
.nav nav{display:flex;align-items:center;gap:28px;font-size:14px;color:var(--ink-2)}
.nav nav a:hover{color:var(--ink)}
.nav .btn{padding:8px 16px;font-size:14px;color:var(--ink)}
.nav .btn:hover{color:var(--paper)}
/* hero */
.hero{padding:84px 0 72px;display:grid;grid-template-columns:1.15fr .85fr;gap:64px;align-items:center}
.hero h1{font-size:clamp(40px,5.6vw,68px);line-height:1.04;margin:18px 0 22px}
.hero h1 em{color:var(--green);font-style:italic}
.hero p.lead{font-size:18px;color:var(--ink-2);max-width:520px}
.cta{display:flex;gap:12px;margin-top:32px;flex-wrap:wrap}
.fine{margin-top:16px;font-size:13px;color:var(--ink-2)}
.partner{display:inline-flex;align-items:center;gap:10px;border:1px solid var(--rule);padding:6px 12px 6px 8px;font-size:13px;font-weight:500;background:var(--paper-2)}
.partner svg{width:18px;height:18px;color:var(--green)}
/* chat mock */
.thread{background:var(--paper-2);border:1px solid var(--rule);padding:20px;display:flex;flex-direction:column;gap:10px}
.thread-h{display:flex;justify-content:space-between;padding-bottom:12px;border-bottom:1px solid var(--rule)}
.msg{max-width:82%;padding:9px 13px;font-size:14px;line-height:1.45;border-radius:2px}
.msg.in{background:var(--paper);border:1px solid var(--rule);align-self:flex-start}
.msg.out{background:var(--wa);align-self:flex-end}
.note{align-self:center;font:500 11px 'IBM Plex Mono',monospace;color:var(--accent);letter-spacing:.05em;text-transform:uppercase;padding:4px 0}
/* ledger strip */
.ledger{border-block:1px solid var(--rule);display:grid;grid-template-columns:repeat(4,1fr)}
.ledger div{padding:26px 24px;border-right:1px solid var(--rule)}
.ledger div:last-child{border:0}
.ledger b{display:block;font:400 34px 'Fraunces',serif;letter-spacing:-.02em}
.ledger span{font-size:13px;color:var(--ink-2)}
/* sections */
section.s{padding:92px 0;border-bottom:1px solid var(--rule)}
.sh{display:grid;grid-template-columns:200px 1fr;gap:32px;margin-bottom:48px}
.sh h2{font-size:clamp(28px,3.6vw,44px);line-height:1.1;max-width:640px}
.caps{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid var(--rule)}
.cap{padding:30px 32px 30px 0;border-bottom:1px solid var(--rule);display:grid;grid-template-columns:44px 1fr;gap:6px}
.cap:nth-child(even){padding-left:32px;padding-right:0;border-left:1px solid var(--rule)}
.cap .n{font:400 13px 'IBM Plex Mono',monospace;color:var(--accent);padding-top:4px}
.cap h3{font:600 20px 'Fraunces',serif;margin-bottom:6px}
.cap p{color:var(--ink-2);font-size:15px}
.steps{display:grid;grid-template-columns:repeat(4,1fr);gap:0}
.step{padding:0 24px 0 0;border-top:2px solid var(--ink);padding-top:18px}
.step .mono{color:var(--accent)}
.step h3{font:600 19px 'Fraunces',serif;margin:8px 0 6px}
.step p{font-size:14.5px;color:var(--ink-2)}
.band{background:var(--paper-2)}
/* pricing */
.plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));border:1px solid var(--rule);background:var(--paper)}
.plan{padding:30px 26px;border-right:1px solid var(--rule);display:flex;flex-direction:column}
.plan:last-child{border:0}
.plan.hot{background:var(--ink);color:var(--paper)}
.plan.hot .mono,.plan.hot li{color:inherit;opacity:.8}
.plan h3{font:600 22px 'Fraunces',serif}
.price{font:400 40px 'Fraunces',serif;margin:10px 0 18px;letter-spacing:-.02em}
.price small{font:400 14px 'IBM Plex Sans';opacity:.65}
.plan ul{list-style:none;font-size:14px;color:var(--ink-2);margin:20px 0 0;display:grid;gap:8px}
.plan li::before{content:"— ";color:var(--accent)}
.plan .btn{text-align:center;margin-top:auto}
.plan.hot .btn{background:var(--paper);color:var(--ink);border-color:var(--paper)}
.plan ul{margin-bottom:24px}
/* faq */
details{border-bottom:1px solid var(--rule);padding:20px 0}
details:first-of-type{border-top:1px solid var(--rule)}
summary{cursor:pointer;font:600 19px 'Fraunces',serif;list-style:none;display:flex;justify-content:space-between;gap:20px}
summary::-webkit-details-marker{display:none}
summary::after{content:"+";font:400 24px 'IBM Plex Mono';color:var(--accent)}
details[open] summary::after{content:"–"}
details p{margin-top:10px;color:var(--ink-2);max-width:680px}
/* closing */
.close{padding:96px 0;text-align:left;display:grid;grid-template-columns:1.3fr 1fr;gap:48px;align-items:end}
.close h2{font-size:clamp(32px,4.4vw,54px);line-height:1.06}
footer{border-top:1px solid var(--rule);padding:32px 0 44px;font-size:13px;color:var(--ink-2)}
footer .wrap{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap}
footer a:hover{color:var(--ink)}
.disc{max-width:1120px;margin:0 auto;padding:0 24px 12px;font-size:12px;color:var(--ink-2);opacity:.8}
@media(max-width:860px){
  .hero,.close{grid-template-columns:1fr;gap:40px}.hero{padding:52px 0}
  .ledger{grid-template-columns:1fr 1fr}.ledger div:nth-child(2){border-right:0}.ledger div:nth-child(-n+2){border-bottom:1px solid var(--rule)}
  .sh{grid-template-columns:1fr;gap:10px}
  .caps{grid-template-columns:1fr}.cap,.cap:nth-child(even){padding:26px 0;border-left:0}
  .steps{grid-template-columns:1fr 1fr;gap:32px 0}
  .nav nav a:not(.btn){display:none}
  section.s{padding:64px 0}
}
@media(max-width:480px){.steps{grid-template-columns:1fr}.plan{border-right:0;border-bottom:1px solid var(--rule)}}
</style>
</head>
<body>

<header><div class="wrap nav">
  <a href="{{ route('home') }}" class="brand"><img src="/teamzo-icon.png" alt="">Teamzo</a>
  <nav>
    <a href="#platform">Platform</a><a href="#how">How it works</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a>
    <a href="{{ $appUrl }}/login" class="btn line">Log in</a>
  </nav>
</div></header>

<main>
<div class="wrap hero">
  <div>
    @if($partner)
    <span class="partner">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg>
      {{ $partner }}
    </span>
    @endif
    <h1 class="serif" style="margin-top:22px">Every customer chat, <em>handled by a team</em> — not a phone.</h1>
    <p class="lead">Teamzo puts your WhatsApp Business number on a shared inbox, with campaigns, automation and a lead pipeline attached. Built on Meta’s official WhatsApp Business Platform.</p>
    <div class="cta">
      <a href="{{ route('register') }}" class="btn fill">Start 14-day trial</a>
      <a href="{{ route('contact') }}" class="btn line">Talk to us</a>
    </div>
    <p class="fine">No card required · 1,000 free messages on sign-up</p>
  </div>

  <div class="thread" aria-hidden="true">
    <div class="thread-h"><span class="mono">Inbox · Admissions</span><span class="mono">3 online</span></div>
    <div class="msg in">Hi, is the weekend batch still open?</div>
    <div class="msg out">Yes — two seats left. Want the fee details?</div>
    <div class="note">Lead created · assigned to Anita</div>
    <div class="msg in">Please send them.</div>
    <div class="msg out">Sent. Anita will call you at 5 pm.</div>
  </div>
</div>

<div class="wrap"><div class="ledger">
  <div><b>5</b><span>numbers per account</span></div>
  <div><b>24 h</b><span>duplicate-send protection</span></div>
  <div><b>5</b><span>interface languages</span></div>
  <div><b>1,000</b><span>free messages to start</span></div>
</div></div>

<section class="s" id="platform"><div class="wrap">
  <div class="sh"><span class="mono">01 — Platform</span><h2 class="serif">Everything a WhatsApp-first business runs on, in one place.</h2></div>
  <div class="caps">
    @foreach($caps as $i => $c)
    <div class="cap"><span class="n">{{ str_pad($i+1,2,'0',STR_PAD_LEFT) }}</span><div><h3>{{ $c[0] }}</h3><p>{{ $c[1] }}</p></div></div>
    @endforeach
  </div>
</div></section>

<section class="s band" id="how"><div class="wrap">
  <div class="sh"><span class="mono">02 — Setup</span><h2 class="serif">Live on your own number in an afternoon.</h2></div>
  <div class="steps">
    <div class="step"><span class="mono">Step 1</span><h3>Create the account</h3><p>Sign up and invite your team. Trial credits are added instantly.</p></div>
    <div class="step"><span class="mono">Step 2</span><h3>Connect Meta</h3><p>Link your WhatsApp Business number through your Meta Business account.</p></div>
    <div class="step"><span class="mono">Step 3</span><h3>Set the rules</h3><p>Templates, labels and flows decide who answers what, and when.</p></div>
    <div class="step"><span class="mono">Step 4</span><h3>Start talking</h3><p>Replies, campaigns and leads all move through the same workspace.</p></div>
  </div>
</div></section>

<section class="s" id="pricing"><div class="wrap">
  <div class="sh"><span class="mono">03 — Pricing</span><h2 class="serif">Plans priced on seats and numbers, not surprises.</h2></div>
  <div class="plans">
    @foreach($plans as $plan)
    @php
      $hot = strtolower($plan->name) === 'growth';
      $feat = is_array($plan->features) ? array_slice($plan->features, 0, 4) : [];
    @endphp
    <div class="plan {{ $hot ? 'hot' : '' }}">
      <span class="mono">{{ $hot ? 'Most chosen' : 'Plan' }}</span>
      <h3>{{ $plan->name }}</h3>
      <div class="price">₹{{ number_format($plan->price) }}<small> / month</small></div>
      <ul>
        <li>{{ $plan->messages_limit ? number_format($plan->messages_limit).' messages' : 'Unlimited messages' }}</li>
        <li>{{ $plan->max_users ?? 'Unlimited' }} users</li>
        <li>{{ $plan->max_phone_numbers ?? 1 }} WhatsApp number{{ ($plan->max_phone_numbers ?? 1) > 1 ? 's' : '' }}</li>
        @foreach($feat as $f)<li>{{ $f }}</li>@endforeach
      </ul>
      <a class="btn line" href="{{ $plan->price == 0 ? route('register') : route('plans.show', strtolower($plan->name)) }}">{{ $plan->price == 0 ? 'Start free trial' : 'Choose '.$plan->name }}</a>
    </div>
    @endforeach
  </div>
  <p class="fine" style="margin-top:18px"><a href="{{ route('pricing') }}" style="text-decoration:underline">Compare every plan in detail</a></p>
</div></section>

<section class="s band" id="faq"><div class="wrap">
  <div class="sh"><span class="mono">04 — Questions</span><h2 class="serif">Before you sign up.</h2></div>
  <div style="max-width:780px">
    @foreach($faqs as $f)
    <details><summary>{{ $f[0] }}</summary><p>{{ $f[1] }}</p></details>
    @endforeach
  </div>
</div></section>

<div class="wrap close">
  <h2 class="serif">Give your WhatsApp number a team behind it.</h2>
  <div>
    <div class="cta" style="margin-top:0">
      <a href="{{ route('register') }}" class="btn fill">Start free trial</a>
      <a href="{{ $appUrl }}/login" class="btn line">Log in</a>
    </div>
  </div>
</div>
</main>

<footer>
  <div class="disc">WhatsApp and Meta are trademarks of Meta Platforms, Inc. Teamzo is an independent product and is not endorsed by or affiliated with WhatsApp or Meta except as described by its partner status.</div>
  <div class="wrap">
    <span>© {{ date('Y') }} Teamzo · {{ config('landing.address') }}</span>
    <span style="display:flex;gap:20px;flex-wrap:wrap">
      <a href="{{ route('features') }}">Features</a><a href="{{ route('pricing') }}">Pricing</a><a href="{{ route('contact') }}">Contact</a>
      <a href="mailto:{{ config('landing.contact_email') }}">{{ config('landing.contact_email') }}</a>
    </span>
  </div>
</footer>
</body>
</html>
