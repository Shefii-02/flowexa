{{-- resources/views/landing/pricing.blade.php --}}
@extends('layouts.landing')
@section('title', 'Pricing — WA SaaS Platform')
@section('meta_description', 'Simple, transparent pricing. Start free with 1,000 messages. Upgrade as you grow. Monthly and yearly plans available.')

@section('content')

<section style="padding:60px 0 40px;background:linear-gradient(135deg,#f0fdf9,#e6f7f1)">
  <div class="container text-center">
    <div class="section-label">💰 Pricing</div>
    <h1 class="section-title" style="font-size:clamp(32px,5vw,52px)">Simple, transparent pricing</h1>
    <p class="section-sub" style="margin:12px auto 0">Start free. Pay as you grow. No hidden fees.</p>

    {{-- Duration toggle --}}
    <div class="duration-tabs" style="justify-content:center;margin-top:32px" id="billing-tabs">
      <button class="dur-tab active" data-dur="monthly" onclick="setBilling('monthly',this)">Monthly</button>
      <button class="dur-tab" data-dur="3month" onclick="setBilling('3month',this)">3 Months<span class="save-tag">-5%</span></button>
      <button class="dur-tab" data-dur="6month" onclick="setBilling('6month',this)">6 Months<span class="save-tag">-10%</span></button>
      <button class="dur-tab" data-dur="yearly" onclick="setBilling('yearly',this)">Yearly<span class="save-tag">-20%</span></button>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    {{-- Plan cards --}}
    <div class="grid-4" style="align-items:start;margin-bottom:60px">
      @foreach($plans as $plan)
      @php
        $popular = strtolower($plan->name) === 'growth';
        $features = is_array($plan->features) ? $plan->features : [];
        $basePrice = $plan->price;
      @endphp
      <div class="pricing-card {{ $popular ? 'popular' : '' }}" style="transition:all .2s">
        @if($popular)<div class="popular-badge">⭐ Most Popular</div>@endif
        <div class="pricing-name">{{ $plan->name }}</div>
        <div class="pricing-desc" style="font-size:13px;color:var(--muted);margin:8px 0 16px;min-height:36px">
          {{ $plan->name === 'Trial' ? '14 days free, no card needed' :
             ($plan->name === 'Starter' ? 'For small businesses & freelancers' :
             ($plan->name === 'Growth' ? 'For growing sales teams' : 'For large enterprises')) }}
        </div>

        {{-- Dynamic price shown by JS --}}
        <div class="pricing-price" style="margin-bottom:4px">
          <sup>₹</sup>
          <span class="plan-price" data-base="{{ $basePrice }}">{{ number_format($basePrice) }}</span>
          <span style="font-size:14px;color:var(--muted)">/month</span>
        </div>
        <p class="price-billed text-sm text-muted" style="margin-bottom:20px;min-height:20px">
          {{ $basePrice == 0 ? '14-day free trial' : 'Billed monthly' }}
        </p>

        <a href="{{ $plan->price == 0 ? route('register') : route('plans.show', strtolower($plan->name)) }}"
           class="btn {{ $popular ? 'btn-primary' : 'btn-outline' }} w-full plan-cta-btn"
           style="justify-content:center;margin-bottom:24px"
           data-plan-slug="{{ strtolower($plan->name) }}"
           data-plan-price="{{ $basePrice }}">
          {{ $plan->price == 0 ? 'Start free trial' : 'Choose '.$plan->name }}
        </a>

        <ul class="pricing-features">
          <li>{{ number_format($plan->messages_limit) }} messages/month</li>
          <li>{{ $plan->max_users ?? 'Unlimited' }} users</li>
          <li>{{ $plan->max_phone_numbers ?? 1 }} phone number{{ ($plan->max_phone_numbers ?? 1) > 1 ? 's' : '' }}</li>
          <li>{{ $plan->max_campaigns ? $plan->max_campaigns.' campaigns' : 'Unlimited campaigns' }}</li>
          <li>{{ $plan->max_contacts ? number_format($plan->max_contacts).' contacts' : 'Unlimited contacts' }}</li>
          <li>{{ $plan->max_templates ? $plan->max_templates.' templates' : 'Unlimited templates' }}</li>
          <li>{{ $plan->max_flow_nodes ? $plan->max_flow_nodes.' flow nodes' : 'Unlimited flow nodes' }}</li>
          <li>⚡ {{ $plan->throttle_per_minute }} msgs/min</li>
          @foreach($features as $f)
          <li>{{ $f }}</li>
          @endforeach
          @if($plan->price == 0)
          <li class="dim">OTP API</li>
          <li class="dim">CRM integration</li>
          <li class="dim">Advanced analytics</li>
          @endif
        </ul>
      </div>
      @endforeach
    </div>

    {{-- Full comparison table --}}
    <div class="card" style="overflow:hidden;padding:0">
      <div style="padding:28px 32px;border-bottom:1px solid var(--border)">
        <h2 style="font-size:22px;font-weight:800">Full feature comparison</h2>
        <p class="text-muted text-sm mt-2">Everything included in each plan</p>
      </div>
      <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;font-size:14px">
          <thead>
            <tr style="background:var(--bg)">
              <th style="text-align:left;padding:14px 20px;font-weight:700;width:35%">Feature</th>
              @foreach($plans as $plan)
              <th style="text-align:center;padding:14px 16px;font-weight:700;color:{{ strtolower($plan->name)==='growth' ? 'var(--brand)' : 'var(--text)' }}">
                {{ $plan->name }}
              </th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @php
            $rows = [
              ['Messages/month','messages_limit','number'],
              ['Users','max_users','number'],
              ['Phone numbers (WA)','max_phone_numbers','number'],
              ['Campaigns','max_campaigns','number'],
              ['Contacts','max_contacts','number'],
              ['Labels','max_labels','number'],
              ['Flow nodes','max_flow_nodes','number'],
              ['Throttle (msgs/min)','throttle_per_minute','number'],
            ];
            $boolRows = [
              ['Flow builder','flow_builder'],
              ['Bulk campaigns','campaigns'],
              ['Lead pipeline','leads'],
              ['OTP API','otp'],
              ['WA Templates','templates'],
              ['CSV import/export','csv'],
              ['Analytics dashboard','analytics'],
              ['Advanced analytics','advanced_analytics'],
              ['Firebase push notifications','firebase'],
              ['CRM integration','crm'],
              ['Webhook support','webhook'],
              ['Multi-language UI','multilang'],
              ['Meta Ads integration','meta_ads'],
              ['Priority support','priority_support'],
              ['Custom branding','custom_branding'],
              ['White-label option','whitelabel'],
            ];
            $planFeatureMap = [];
            foreach($plans as $p) {
              $f = is_array($p->features) ? array_map('strtolower', $p->features) : [];
              $planFeatureMap[$p->id] = $f;
            }
            $featureChecks = [
              'flow_builder'       => [true,true,true,true],
              'campaigns'          => [true,true,true,true],
              'leads'              => [true,true,true,true],
              'otp'                => [false,true,true,true],
              'templates'          => [true,true,true,true],
              'csv'                => [true,true,true,true],
              'analytics'          => [true,true,true,true],
              'advanced_analytics' => [false,false,true,true],
              'firebase'           => [false,false,true,true],
              'crm'                => [false,false,true,true],
              'webhook'            => [true,true,true,true],
              'multilang'          => [true,true,true,true],
              'meta_ads'           => [false,false,true,true],
              'priority_support'   => [false,false,false,true],
              'custom_branding'    => [false,false,false,true],
              'whitelabel'         => [false,false,false,true],
            ];
            @endphp
            {{-- Numeric rows --}}
            @foreach($rows as $i => $row)
            <tr style="background:{{ $i%2==0 ? '#fff' : 'var(--bg)' }}">
              <td style="padding:12px 20px;font-weight:500">{{ $row[0] }}</td>
              @foreach($plans as $plan)
              <td style="text-align:center;padding:12px 16px">
                @php $val = $plan->{$row[1]} @endphp
                {{ $val === null ? '∞' : number_format($val) }}
              </td>
              @endforeach
            </tr>
            @endforeach
            {{-- Section header --}}
            <tr style="background:var(--brand-light)">
              <td colspan="{{ $plans->count() + 1 }}" style="padding:10px 20px;font-size:11px;font-weight:700;color:var(--brand);text-transform:uppercase;letter-spacing:.06em">Features included</td>
            </tr>
            {{-- Bool rows --}}
            @foreach($boolRows as $i => $row)
            <tr style="background:{{ $i%2==0 ? '#fff' : 'var(--bg)' }}">
              <td style="padding:12px 20px;font-weight:500">{{ $row[0] }}</td>
              @foreach($plans as $pi => $plan)
              <td style="text-align:center;padding:12px 16px;font-size:18px">
                @if($featureChecks[$row[1]][$pi] ?? false)
                  <span style="color:#16a34a">✓</span>
                @else
                  <span style="color:#e2e8f0">—</span>
                @endif
              </td>
              @endforeach
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    {{-- Topup info --}}
    <div style="background:var(--bg);border:1px solid var(--border);border-radius:16px;padding:32px;margin-top:32px">
      <div class="flex items-center gap-4" style="flex-wrap:wrap">
        <div style="flex:1;min-width:240px">
          <h3 style="font-size:20px;font-weight:800;margin-bottom:8px">Need more messages?</h3>
          <p class="text-muted" style="font-size:14px">Top up your wallet anytime. Credits never expire. One-time purchase, no subscription needed.</p>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          @php
          $topups = [['100','₹29'],['500','₹99'],['1,000','₹179'],['5,000','₹749'],['10,000','₹1,199']];
          @endphp
          @foreach($topups as $t)
          <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;text-align:center;min-width:90px">
            <p style="font-size:16px;font-weight:800">{{ $t[0] }}</p>
            <p style="font-size:12px;color:var(--muted)">messages</p>
            <p style="font-size:14px;font-weight:700;color:var(--brand);margin-top:4px">{{ $t[1] }}</p>
          </div>
          @endforeach
        </div>
      </div>
    </div>
  </div>
</section>

{{-- FAQ --}}
<section class="section section-alt">
  <div class="container-sm">
    <div class="text-center mb-12">
      <div class="section-label">❓ FAQ</div>
      <h2 class="section-title">Pricing questions</h2>
    </div>
    @php
    $faqs = [
      ['Can I switch plans anytime?','Yes. Upgrade or downgrade at any time. When you upgrade, the new plan activates immediately. No refunds for unused days on the current plan.'],
      ['What happens after my trial ends?','After 14 days your account switches to a limited mode. You can still login and view your data. Purchase any plan to reactivate full access.'],
      ['Do message credits expire?','No. Message credits in your wallet never expire. They roll over month to month until used.'],
      ['Is there a setup fee?','No setup fee. No hidden fees. The price you see is the price you pay.'],
      ['Can I pay yearly to save?','Yes. Yearly billing saves 20%. 6-month billing saves 10%. 3-month billing saves 5%.'],
      ['What payment methods are accepted?','We use Razorpay which accepts all major credit/debit cards, UPI, net banking, and wallets.'],
    ]
    @endphp
    @foreach($faqs as $faq)
    <div class="faq-item">
      <div class="faq-q"><span>{{ $faq[0] }}</span><span class="faq-icon">+</span></div>
      <div class="faq-a">{{ $faq[1] }}</div>
    </div>
    @endforeach
  </div>
</section>

@push('scripts')
<script>
const multipliers = { monthly: 1, '3month': 3*0.95, '6month': 6*0.90, yearly: 12*0.80 }
const labels = { monthly: 'Billed monthly', '3month': 'Billed every 3 months (5% off)', '6month': 'Billed every 6 months (10% off)', yearly: 'Billed yearly (20% off)' }
let currentDur = 'monthly'

function setBilling(dur, el) {
  currentDur = dur
  document.querySelectorAll('.dur-tab').forEach(t => t.classList.remove('active'))
  el.classList.add('active')

  document.querySelectorAll('.plan-price').forEach(el => {
    const base = parseFloat(el.dataset.base)
    el.textContent = Math.round(base * multipliers[dur]).toLocaleString('en-IN')
  })
  document.querySelectorAll('.price-billed').forEach((el, i) => {
    const base = parseFloat(document.querySelectorAll('.plan-price')[i]?.dataset.base || 0)
    el.textContent = base == 0 ? '14-day free trial' : labels[dur]
  })
  // Update CTA links
  document.querySelectorAll('.plan-cta-btn').forEach(btn => {
    const slug  = btn.dataset.planSlug
    const price = parseFloat(btn.dataset.planPrice)
    if (price > 0) btn.href = `/plans/${slug}?duration=${dur}`
  })
}
</script>
@endpush

@endsection
