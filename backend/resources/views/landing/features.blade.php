{{-- resources/views/landing/features.blade.php --}}
@extends('layouts.landing')
@section('title', 'Features — WA SaaS Platform')
@section('meta_description', 'Explore all features: flow builder, bulk campaigns, lead management, OTP API, analytics, Meta Ads integration and more.')

@section('content')

{{-- Hero --}}
<section style="padding:60px 0 50px;background:linear-gradient(135deg,#f0fdf9,#e6f7f1)">
  <div class="container text-center">
    <div class="section-label">✨ Features</div>
    <h1 class="section-title" style="font-size:clamp(32px,5vw,52px)">Everything your team needs</h1>
    <p class="section-sub" style="margin:12px auto 0;max-width:620px">
      One platform built for WhatsApp automation, lead management, and business growth.
      No stitching multiple tools together.
    </p>
    <div class="flex items-center" style="justify-content:center;gap:12px;flex-wrap:wrap;margin-top:28px">
      <a href="{{ route('register') }}" class="btn btn-primary btn-lg">Start free trial</a>
      <a href="{{ route('pricing') }}"  class="btn btn-outline btn-lg">See pricing</a>
    </div>
  </div>
</section>

{{-- Quick nav --}}
<div style="background:#fff;border-bottom:1px solid var(--border);position:sticky;top:68px;z-index:50">
  <div class="container">
    <div style="display:flex;gap:4px;overflow-x:auto;padding:10px 0">
      @php
      $navs = [
        ['#flow','🌿 Flow Builder'],['#campaigns','📢 Campaigns'],['#leads','🎯 Leads'],
        ['#otp','🔑 OTP API'],['#analytics','📊 Analytics'],['#billing','💰 Billing'],
        ['#meta-ads','📣 Meta Ads'],['#multilang','🌐 Languages'],
      ]
      @endphp
      @foreach($navs as $n)
      <a href="{{ $n[0] }}" style="white-space:nowrap;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;color:var(--muted);transition:all .15s" onmouseover="this.style.background='var(--brand-light)';this.style.color='var(--brand)'" onmouseout="this.style.background='';this.style.color='var(--muted)'">
        {{ $n[1] }}
      </a>
      @endforeach
    </div>
  </div>
</div>

{{-- ── FLOW BUILDER ── --}}
<section class="section" id="flow">
  <div class="container">
    <div class="grid-2" style="align-items:center;gap:60px">
      <div>
        <div class="section-label">🌿 Flow Builder</div>
        <h2 class="section-title">WhatsApp chatbot without code</h2>
        <p class="section-sub">Build interactive WhatsApp conversation trees with buttons and list menus. When a customer replies, the flow routes them automatically and creates a lead.</p>
        <ul style="list-style:none;margin-top:24px;space-y:12px">
          @php $flowFeats = [
            'Drag-and-drop node builder — button, list, and text types',
            'Up to 5 levels deep, unlimited child nodes per level',
            'Auto-create lead + assign to counsellor at leaf nodes',
            'Set lead category per node for pipeline segmentation',
            'Flow session tracking — resume where customer left off',
            'Circular-reference protection prevents infinite loops',
            'Per-node trigger count analytics',
          ] @endphp
          @foreach($flowFeats as $f)
          <li style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px">
            <span style="color:var(--brand);font-weight:700;flex-shrink:0">✓</span> {{ $f }}
          </li>
          @endforeach
        </ul>
      </div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:18px;padding:28px;font-family:monospace;font-size:12px;line-height:2">
        <div style="color:#34d399;font-weight:700">📱 Welcome (Root)</div>
        <div style="color:#60a5fa;padding-left:20px">├── 1️⃣ SaaS Products</div>
        <div style="color:#f9a8d4;padding-left:40px">│ ├── UniCRM Demo → <span style="color:#a78bfa">LEAD</span></div>
        <div style="color:#f9a8d4;padding-left:40px">│ └── UniERP Enquiry → <span style="color:#a78bfa">LEAD</span></div>
        <div style="color:#60a5fa;padding-left:20px">├── 2️⃣ Web Development</div>
        <div style="color:#f9a8d4;padding-left:40px">│ └── Consultation → <span style="color:#a78bfa">LEAD</span></div>
        <div style="color:#60a5fa;padding-left:20px">├── 3️⃣ Mobile App</div>
        <div style="color:#f9a8d4;padding-left:40px">│ └── App Quote → <span style="color:#a78bfa">LEAD</span></div>
        <div style="color:#60a5fa;padding-left:20px">└── 4️⃣ Support</div>
        <div style="color:#f9a8d4;padding-left:40px">&nbsp;&nbsp;&nbsp;&nbsp;└── General Enquiry → <span style="color:#a78bfa">LEAD</span></div>
        <div style="margin-top:16px;padding:10px;background:rgba(29,158,117,.1);border-radius:8px;color:var(--brand);font-family:Inter,sans-serif;font-size:11px">
          ✅ Every leaf node auto-creates a lead and notifies the assigned counsellor via Firebase push
        </div>
      </div>
    </div>
  </div>
</section>

{{-- ── CAMPAIGNS ── --}}
<section class="section section-alt" id="campaigns">
  <div class="container">
    <div class="grid-2" style="align-items:center;gap:60px">
      <div style="background:#fff;border:1px solid var(--border);border-radius:18px;padding:28px">
        @php
        $stats = [['📢','Campaign','Univexa July Promo'],['👥','Contacts targeted','5,000'],['✅','Delivered','4,847'],['📖','Read','3,291'],['⚡','Throttle','60/min'],['🕐','Status','Completed']];
        @endphp
        @foreach($stats as $s)
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);font-size:14px">
          <span style="color:var(--muted)">{{ $s[0] }} {{ $s[1] }}</span>
          <span style="font-weight:600;color:var(--text)">{{ $s[2] }}</span>
        </div>
        @endforeach
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:20px">
          <div style="text-align:center;background:var(--brand-light);border-radius:10px;padding:12px">
            <p style="font-size:22px;font-weight:800;color:var(--brand)">96.9%</p>
            <p style="font-size:11px;color:var(--muted)">Delivery rate</p>
          </div>
          <div style="text-align:center;background:#f0fdf4;border-radius:10px;padding:12px">
            <p style="font-size:22px;font-weight:800;color:#16a34a">65.8%</p>
            <p style="font-size:11px;color:var(--muted)">Read rate</p>
          </div>
          <div style="text-align:center;background:#eff6ff;border-radius:10px;padding:12px">
            <p style="font-size:22px;font-weight:800;color:#3b82f6">0</p>
            <p style="font-size:11px;color:var(--muted)">Failed</p>
          </div>
        </div>
      </div>
      <div>
        <div class="section-label">📢 Bulk Campaigns</div>
        <h2 class="section-title">Reach thousands in minutes</h2>
        <p class="section-sub">Send WhatsApp messages to all your contacts or filter by labels. Full throttle control, live stats, and pause/resume support.</p>
        <ul style="list-style:none;margin-top:24px">
          @php $campFeats = [
            'Target all contacts, by label, or custom CSV upload',
            'Throttle control — 10 to 1,000 messages per minute',
            'Real-time delivery, read, and failed stats',
            'Pause mid-campaign, resume without losing progress',
            'Resend failed messages with one click',
            'Wallet auto-debited before campaign launch',
            '24-hour dedup — no contact gets same message twice in a day',
            'A/B test two campaigns and compare delivery rates',
          ] @endphp
          @foreach($campFeats as $f)
          <li style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px">
            <span style="color:var(--brand);font-weight:700;flex-shrink:0">✓</span> {{ $f }}
          </li>
          @endforeach
        </ul>
      </div>
    </div>
  </div>
</section>

{{-- ── LEADS ── --}}
<section class="section" id="leads">
  <div class="container">
    <div class="grid-2" style="align-items:center;gap:60px">
      <div>
        <div class="section-label">🎯 Lead Management</div>
        <h2 class="section-title">Full CRM pipeline built-in</h2>
        <p class="section-sub">Auto-create leads from WhatsApp flows. Manage your pipeline with Kanban or table view. Assign, track, score, and convert.</p>
        <ul style="list-style:none;margin-top:24px">
          @php $leadFeats = [
            'Kanban board — New → Contacted → Follow Up → Enrolled → Lost',
            'Auto-create leads when customers hit flow leaf nodes',
            'Assign to counsellors manually or via bulk assign',
            'Role-based access — counsellors see only their own leads',
            'Lead scoring rules — award points for actions',
            'Full activity timeline — every stage change logged',
            'CSV import and export with field mapping',
            'Firebase push notification on every assignment',
          ] @endphp
          @foreach($leadFeats as $f)
          <li style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px">
            <span style="color:var(--brand);font-weight:700;flex-shrink:0">✓</span> {{ $f }}
          </li>
          @endforeach
        </ul>
      </div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:18px;padding:24px">
        <div style="display:flex;gap:10px;overflow-x:auto;padding-bottom:8px">
          @php
          $stages = [
            ['New','#dbeafe','#1d4ed8',3],
            ['Contacted','#fef3c7','#92400e',5],
            ['Follow Up','#ede9fe','#7c3aed',4],
            ['Enrolled','#dcfce7','#16a34a',2],
            ['Lost','#fee2e2','#991b1b',1],
          ]
          @endphp
          @foreach($stages as $s)
          <div style="flex-shrink:0;width:130px">
            <div style="background:{{ $s[1] }};color:{{ $s[2] }};font-size:11px;font-weight:700;padding:6px 10px;border-radius:8px;margin-bottom:8px;text-align:center">
              {{ $s[0] }} ({{ $s[3] }})
            </div>
            @for($i = 0; $i < min($s[3],2); $i++)
            <div style="background:#fff;border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:8px;font-size:11px">
              <div style="font-weight:600;color:var(--text)">{{ ['Priya Nair','Rahul Thomas','Anjali Menon','Sanjay Kumar','Meera Pillai','Kiran Babu'][$i + array_search($s, $stages)*1] ?? 'Contact' }}</div>
              <div style="color:var(--muted);margin-top:3px">{{ ['UniCRM Demo','Mobile App','Web Dev','UniERP','SaaS'][$i] ?? 'Enquiry' }}</div>
              <div style="background:{{ $s[1] }};color:{{ $s[2] }};font-size:10px;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:5px">{{ ['High','Medium','High','Low'][$i % 4] }}</div>
            </div>
            @endfor
          </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>
</section>

{{-- ── OTP ── --}}
<section class="section section-alt" id="otp">
  <div class="container">
    <div class="grid-2" style="align-items:center;gap:60px">
      <div style="background:#1e293b;border-radius:18px;padding:28px;font-family:monospace;font-size:12px;line-height:1.9;overflow-x:auto">
        <div style="color:#64748b;margin-bottom:12px"># Send OTP via WhatsApp</div>
        <div style="color:#34d399">POST</div> <span style="color:#f1f5f9">/api/v1/otp/send</span>
        <div style="margin-top:8px;color:#64748b">Headers:</div>
        <div style="color:#60a5fa">  X-App-Id: <span style="color:#fcd34d">WA_APP_UNIVEXA2024</span></div>
        <div style="color:#60a5fa">  X-Private-Token: <span style="color:#fcd34d">uxi_tok_xxxx...</span></div>
        <div style="margin-top:8px;color:#64748b">Body:</div>
        <div style="color:#f1f5f9">  {</div>
        <div style="color:#f1f5f9">    <span style="color:#60a5fa">"phone"</span>: <span style="color:#fcd34d">"918086544821"</span>,</div>
        <div style="color:#f1f5f9">    <span style="color:#60a5fa">"device_id"</span>: <span style="color:#fcd34d">"device-001"</span></div>
        <div style="color:#f1f5f9">  }</div>
        <div style="margin-top:16px;color:#34d399">✅ Response:</div>
        <div style="color:#f1f5f9">  { <span style="color:#60a5fa">"ref_id"</span>: <span style="color:#fcd34d">"uuid"</span>,</div>
        <div style="color:#f1f5f9">    <span style="color:#60a5fa">"expires_in"</span>: <span style="color:#fcd34d">900</span> }</div>
        <div style="margin-top:16px;color:#64748b"># OTP delivered to customer's WhatsApp</div>
        <div style="color:#64748b"># Verify with ref_id + otp + device_id</div>
      </div>
      <div>
        <div class="section-label">🔑 OTP Service</div>
        <h2 class="section-title">WhatsApp OTP for your app</h2>
        <p class="section-sub">Add WhatsApp OTP to any app with just 2 API calls. Customers receive a 6-digit code on WhatsApp — familiar, trusted, no SMS cost.</p>
        <ul style="list-style:none;margin-top:24px">
          @php $otpFeats = [
            'Simple REST API — 2 endpoints: send + verify',
            'Dedicated X-App-Id + X-Private-Token authentication',
            'Device-ID binding prevents OTP reuse across devices',
            '15-minute expiry, 3 max attempts before auto-expire',
            'Rate limiting — 5 OTPs per phone per hour',
            'Wallet-integrated — each OTP deducted from credits',
            'Full send/verify log in your dashboard',
            'Works with any tech stack: React, Flutter, Laravel, Node',
          ] @endphp
          @foreach($otpFeats as $f)
          <li style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px">
            <span style="color:var(--brand);font-weight:700;flex-shrink:0">✓</span> {{ $f }}
          </li>
          @endforeach
        </ul>
      </div>
    </div>
  </div>
</section>

{{-- ── ANALYTICS ── --}}
<section class="section" id="analytics">
  <div class="container">
    <div class="text-center mb-12">
      <div class="section-label">📊 Analytics</div>
      <h2 class="section-title">Data-driven insights for your team</h2>
      <p class="section-sub" style="margin:12px auto 0">Go beyond basic stats. Understand cohort behaviour, staff performance, and wallet burn rate.</p>
    </div>
    <div class="grid-3">
      @php
      $analyticsCards = [
        ['📊','Overview Dashboard','Messages sent, delivered, read. Contacts, leads, campaigns — all in one daily snapshot.'],
        ['👥','Staff Comparison','Compare counsellors side by side — leads assigned, enrolled, conversion rate, response time.'],
        ['🔄','Cohort Analysis','Track leads created this week vs enrolled over time. Identify drop-off points in your pipeline.'],
        ['⏰','Best Send Time','Our AI analyses your historical read rates to find the best hour to send WhatsApp campaigns.'],
        ['🌿','Flow Performance','See which flow nodes get the most traffic, which create the most leads, and where customers drop off.'],
        ['💸','Wallet Burn Rate','Daily credit usage chart with estimated days remaining and low-balance alerts via push notification.'],
        ['🎯','Lead Scoring','Assign points to events (flow reply, campaign read, demo booked). Auto-rank your hottest leads.'],
        ['📈','Campaign A/B Test','Run two campaign variants. Compare delivery rates and read rates. Pick the winner automatically.'],
        ['📅','Daily Snapshots','Pre-aggregated daily stats table. Filter by date range. Export to CSV for your reports.'],
      ]
      @endphp
      @foreach($analyticsCards as $c)
      <div class="card card-hover">
        <div class="feature-icon">{{ $c[0] }}</div>
        <div class="feature-title">{{ $c[1] }}</div>
        <div class="feature-desc">{{ $c[2] }}</div>
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- ── BILLING ── --}}
<section class="section section-alt" id="billing">
  <div class="container">
    <div class="grid-2" style="align-items:center;gap:60px">
      <div>
        <div class="section-label">💰 Wallet & Billing</div>
        <h2 class="section-title">Prepaid credits, zero surprises</h2>
        <p class="section-sub">Buy message credits in advance. Campaigns deduct credits automatically. Top up anytime. Plan subscriptions are billed separately via Razorpay.</p>
        <ul style="list-style:none;margin-top:24px">
          @php $billFeats = [
            'Prepaid wallet — buy credits, use anytime, no expiry',
            'Razorpay integration — cards, UPI, net banking, wallets',
            'Plan billing — monthly, 3-month, 6-month, yearly (up to 20% off)',
            'Topup packages from ₹29 (100 msgs) to ₹1,199 (10,000 msgs)',
            'Low balance alert via Firebase push notification',
            'Full transaction history — credit, debit, reference',
            'Add-ons available — extra phone numbers, message packs',
            'Company-isolated billing — no cross-company data',
          ] @endphp
          @foreach($billFeats as $f)
          <li style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px">
            <span style="color:var(--brand);font-weight:700;flex-shrink:0">✓</span> {{ $f }}
          </li>
          @endforeach
        </ul>
      </div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:18px;padding:28px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
          <div>
            <p style="font-size:13px;color:var(--muted)">Wallet balance</p>
            <p style="font-size:36px;font-weight:800;color:var(--brand)">3,847 <span style="font-size:14px;color:var(--muted);font-weight:400">messages</span></p>
          </div>
          <a href="{{ route('pricing') }}" class="btn btn-primary btn-sm">Top up</a>
        </div>
        @php
        $txns = [
          ['credit','Wallet recharge — 5,000 msgs','Jul 15','+ 5,000'],
          ['debit','Campaign: July Promo','Jul 16','- 847'],
          ['debit','Campaign: Welcome Blast','Jul 17','- 305'],
          ['credit','Wallet recharge — 1,000 msgs','Jul 18','+ 1,000'],
          ['debit','OTP sends (46 OTPs)','Jul 18','- 46'],
        ]
        @endphp
        @foreach($txns as $t)
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);font-size:13px">
          <div>
            <p style="font-weight:500;color:var(--text)">{{ $t[1] }}</p>
            <p style="color:var(--muted);font-size:11px;margin-top:2px">{{ $t[2] }}</p>
          </div>
          <span style="font-weight:700;color:{{ $t[0]==='credit' ? '#16a34a' : '#dc2626' }}">{{ $t[3] }}</span>
        </div>
        @endforeach
      </div>
    </div>
  </div>
</section>

{{-- ── META ADS ── --}}
<section class="section" id="meta-ads">
  <div class="container">
    <div class="grid-2" style="align-items:center;gap:60px">
      <div>
        <div class="section-label">📣 Meta Ads Manager</div>
        <h2 class="section-title">Run Facebook & Instagram ads from here</h2>
        <p class="section-sub">Connect your Meta ad account and manage campaigns without leaving the platform. 20+ pre-built audience templates for Indian markets.</p>
        <ul style="list-style:none;margin-top:24px">
          @php $metaFeats = [
            'Connect your own Meta ad account (multi-tenant, isolated)',
            'Create campaigns — Lead Gen, Traffic, Conversions, Reach',
            '20+ pre-built audience templates: Students, Job seekers, SME owners…',
            'Image, video, and carousel creative builder',
            'Media library — upload and reuse images/videos',
            'Automatic insights sync every 15 minutes',
            'Track reach, impressions, CTR, CPC, spend, ROAS, leads',
            'Ad review status sync — Approved/Rejected/In Review',
            'Note: Meta ad spend billed to your Meta account, not WA wallet',
          ] @endphp
          @foreach($metaFeats as $f)
          <li style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:14px">
            <span style="color:var(--brand);font-weight:700;flex-shrink:0">✓</span> {{ $f }}
          </li>
          @endforeach
        </ul>
      </div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:18px;padding:28px">
        <p style="font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:16px">Campaign insights (last 30 days)</p>
        @php
        $metrics = [
          ['👁️','Reach','1,24,500'],
          ['📺','Impressions','3,87,200'],
          ['🖱️','Clicks','8,340'],
          ['📊','CTR','2.15%'],
          ['💰','Spend','₹18,600'],
          ['🎯','Leads','247'],
          ['💸','Cost/Lead','₹75.30'],
          ['📈','ROAS','3.2x'],
        ]
        @endphp
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          @foreach($metrics as $m)
          <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px">
            <p style="font-size:11px;color:var(--muted)">{{ $m[0] }} {{ $m[1] }}</p>
            <p style="font-size:20px;font-weight:800;color:var(--text);margin-top:4px">{{ $m[2] }}</p>
          </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>
</section>

{{-- ── MULTILANG ── --}}
<section class="section section-alt" id="multilang">
  <div class="container">
    <div class="text-center mb-12">
      <div class="section-label">🌐 Multi-language</div>
      <h2 class="section-title">Full UI in 5 languages</h2>
      <p class="section-sub" style="margin:12px auto 0">Every user can set their own language preference. Arabic includes full RTL layout support.</p>
    </div>
    <div class="grid-4" style="justify-items:center">
      @php
      $langs = [
        ['🇬🇧','English','en','Left to right'],
        ['🇮🇳','Malayalam','ml','ഇടത്തുനിന്ന് വലത്തോട്ട്'],
        ['🇮🇳','Hindi','hi','बाएं से दाएं'],
        ['🇮🇳','Tamil','ta','இடமிருந்து வலம்'],
        ['🇸🇦','Arabic','ar','من اليمين إلى اليسار (RTL)'],
      ]
      @endphp
      @foreach($langs as $l)
      <div class="card text-center" style="width:100%">
        <div style="font-size:36px;margin-bottom:12px">{{ $l[0] }}</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:4px">{{ $l[1] }}</div>
        <div style="font-size:12px;color:var(--muted)">{{ $l[2] }}</div>
        <div style="font-size:12px;color:var(--muted);margin-top:4px;font-style:italic">{{ $l[3] }}</div>
        @if($l[2] === 'ar')
        <div style="background:var(--brand-light);color:var(--brand);font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;display:inline-block;margin-top:10px">RTL Support</div>
        @endif
      </div>
      @endforeach
    </div>
  </div>
</section>

{{-- CTA --}}
<section style="padding:80px 0;background:linear-gradient(135deg,#1D9E75,#157a5a)">
  <div class="container text-center">
    <h2 style="font-size:clamp(28px,4vw,44px);font-weight:800;color:#fff;margin-bottom:16px">Ready to see it all in action?</h2>
    <p style="font-size:18px;color:rgba(255,255,255,.8);margin-bottom:32px">Start your free 14-day trial. 1,000 messages included. No credit card needed.</p>
    <div class="flex items-center" style="justify-content:center;gap:16px;flex-wrap:wrap">
      <a href="{{ route('register') }}" class="btn btn-white btn-lg">Start free trial</a>
      <a href="{{ route('contact') }}"  class="btn btn-lg" style="background:rgba(255,255,255,.15);color:#fff;border:2px solid rgba(255,255,255,.3)">Request a demo</a>
    </div>
  </div>
</section>

@endsection
