{{-- resources/views/landing/plan-checkout.blade.php --}}
@extends('layouts.landing')
@section('title', 'Checkout — {{ $plan->name }} Plan — WA SaaS')
@section('meta_description', 'Purchase the {{ $plan->name }} plan and get your WhatsApp Business platform live today.')

@push('head')
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
@endpush

@section('content')

<section style="padding:40px 0;background:var(--bg);min-height:calc(100vh - 68px)">
  <div class="checkout-wrap">

    {{-- LEFT — Registration form --}}
    <div class="checkout-form-box">

      {{-- Steps --}}
      <div class="steps" style="margin-bottom:32px">
        <div class="step-item done">
          <div class="step-dot">✓</div>
          <div class="step-label">Choose plan</div>
        </div>
        <div class="step-item active">
          <div class="step-dot">2</div>
          <div class="step-label">Your details</div>
        </div>
        <div class="step-item">
          <div class="step-dot">3</div>
          <div class="step-label">Payment</div>
        </div>
        <div class="step-item">
          <div class="step-dot">4</div>
          <div class="step-label">Activate</div>
        </div>
      </div>

      <h2 style="font-size:22px;font-weight:800;margin-bottom:6px">Create your account</h2>
      <p style="font-size:13px;color:var(--muted);margin-bottom:28px">Already have an account? <a href="{{ config('landing.app_url','http://localhost:5173') }}/login" style="color:var(--brand);font-weight:600">Login & upgrade from dashboard</a></p>

      @if(session('error'))
      <div class="alert alert-error">{{ session('error') }}</div>
      @endif
      @if($errors->any())
      <div class="alert alert-error">
        @foreach($errors->all() as $e)<div>• {{ $e }}</div>@endforeach
      </div>
      @endif

      <form id="checkout-form">
        @csrf
        <input type="hidden" name="plan_id" value="{{ $plan->id }}">
        <input type="hidden" name="duration_type" id="duration_type_input" value="monthly">

        {{-- Duration selector --}}
        <div class="form-group">
          <label class="form-label">Billing period</label>
          <div class="duration-tabs" id="dur-tabs">
            <button type="button" class="dur-tab active" onclick="setDur('monthly',this)">Monthly</button>
            <button type="button" class="dur-tab" onclick="setDur('3month',this)">3 Months<span class="save-tag">-5%</span></button>
            <button type="button" class="dur-tab" onclick="setDur('6month',this)">6 Months<span class="save-tag">-10%</span></button>
            <button type="button" class="dur-tab" onclick="setDur('yearly',this)">Yearly<span class="save-tag">-20%</span></button>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="form-group">
            <label class="form-label">Company name *</label>
            <input type="text" id="company_name" name="company_name" class="form-control" placeholder="Univexa Technologies" required>
          </div>
          <div class="form-group">
            <label class="form-label">Your full name *</label>
            <input type="text" id="reg_name" name="name" class="form-control" placeholder="Rahul Menon" required>
          </div>
          <div class="form-group">
            <label class="form-label">Work email *</label>
            <input type="email" id="reg_email" name="email" class="form-control" placeholder="rahul@company.com" required>
          </div>
          <div class="form-group">
            <label class="form-label">Phone number *</label>
            <input type="tel" id="reg_phone" name="phone" class="form-control" placeholder="918086544828" required>
            <p class="form-hint">With country code, no +</p>
          </div>
          <div class="form-group">
            <label class="form-label">Password *</label>
            <input type="password" id="reg_password" name="password" class="form-control" placeholder="Min 8 characters" required>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm password *</label>
            <input type="password" name="password_confirmation" class="form-control" placeholder="Repeat password" required>
          </div>
        </div>

        <button type="button" id="pay-btn" class="btn btn-primary w-full" style="justify-content:center;padding:16px;font-size:16px;margin-top:8px">
          🔒 Pay ₹<span id="btn-price">{{ number_format($plan->price) }}</span> &amp; Activate Account
        </button>

        <div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-top:16px;flex-wrap:wrap">
          <img src="https://cdn.razorpay.com/static/assets/logo/rzp-glyph.svg" alt="Razorpay" style="height:20px;opacity:.6">
          <span style="font-size:12px;color:var(--muted)">🔒 256-bit SSL secured</span>
          <span style="font-size:12px;color:var(--muted)">✅ PCI DSS compliant</span>
        </div>

        <p style="font-size:11px;color:var(--muted);text-align:center;margin-top:12px">
          By purchasing, you agree to our Terms of Service. Secure payment via Razorpay.
          Accepts: Visa, Mastercard, UPI, Net Banking, Wallets.
        </p>
      </form>
    </div>

    {{-- RIGHT — Order summary --}}
    <div class="checkout-summary">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
        <div style="width:44px;height:44px;background:var(--brand);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px">💬</div>
        <div>
          <p style="font-size:13px;color:var(--muted)">WA SaaS Platform</p>
          <div class="checkout-plan-name">{{ $plan->name }} Plan</div>
        </div>
      </div>

      <div style="border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:20px;background:#fff">
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:14px">
          <span style="color:var(--muted)">{{ $plan->name }} plan</span>
          <span style="font-weight:600">₹<span class="summary-price">{{ number_format($plan->price) }}</span>/mo</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:14px;margin-top:8px">
          <span style="color:var(--muted)">Duration</span>
          <span class="summary-dur" style="font-weight:600">Monthly</span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:14px;margin-top:8px" id="discount-row" style="display:none">
          <span style="color:#16a34a">Discount</span>
          <span class="summary-discount" style="color:#16a34a;font-weight:600"></span>
        </div>
        <div style="border-top:1px solid var(--border);margin-top:12px;padding-top:12px;display:flex;justify-content:space-between;align-items:center">
          <span style="font-weight:700;font-size:15px">Total due today</span>
          <span style="font-size:20px;font-weight:800;color:var(--brand)">₹<span class="summary-total">{{ number_format($plan->price) }}</span></span>
        </div>
      </div>

      {{-- What's included --}}
      <p style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:12px">What's included</p>
      @php
      $features = is_array($plan->features) ? $plan->features : [];
      $limits = [
        number_format($plan->messages_limit).' messages/month',
        ($plan->max_users ?? '∞').' users',
        ($plan->max_phone_numbers ?? 1).' phone number'.( ($plan->max_phone_numbers??1) > 1 ? 's' : ''),
        ($plan->max_campaigns ? $plan->max_campaigns.' campaigns' : 'Unlimited campaigns'),
        ($plan->max_contacts ? number_format($plan->max_contacts).' contacts' : 'Unlimited contacts'),
        'Flow builder',
        'WA Campaigns',
        'Lead management',
        'Analytics dashboard',
      ];
      $all = array_merge($limits, $features);
      @endphp
      <ul style="list-style:none;padding:0;margin:0">
        @foreach($all as $f)
        <li style="display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 0;border-bottom:1px solid var(--border)">
          <span style="color:var(--brand);font-weight:700">✓</span> {{ $f }}
        </li>
        @endforeach
        <li style="display:flex;align-items:center;gap:8px;font-size:13px;padding:6px 0">
          <span style="color:var(--brand);font-weight:700">✓</span> 1,000 bonus messages on first month
        </li>
      </ul>

      <div style="background:var(--brand-light);border:1px solid #a7f3d0;border-radius:10px;padding:14px;margin-top:16px">
        <p style="font-size:12px;color:var(--brand);font-weight:600">🎉 You're getting 1,000 bonus messages on your first month!</p>
      </div>

      <div style="margin-top:20px;text-align:center">
        <a href="{{ route('pricing') }}" style="font-size:13px;color:var(--muted)">← Compare all plans</a>
      </div>
    </div>

  </div>
</section>

@push('scripts')
<script>
const BASE_PRICE   = {{ $plan->price }};
const PLAN_SLUG    = '{{ strtolower($plan->name) }}';
const PLAN_ID      = {{ $plan->id }};
const RAZORPAY_KEY = '{{ config("services.razorpay.key") }}';

const multipliers = { monthly:1, '3month':3*0.95, '6month':6*0.90, yearly:12*0.80 };
const durLabels   = { monthly:'Monthly', '3month':'Every 3 months (5% off)', '6month':'Every 6 months (10% off)', yearly:'Yearly (20% off)' };
let currentDur = 'monthly';

function setDur(dur, el) {
  currentDur = dur;
  document.querySelectorAll('.dur-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('duration_type_input').value = dur;

  const total = Math.round(BASE_PRICE * multipliers[dur]);
  const disc  = Math.round(BASE_PRICE * multipliers[dur] - BASE_PRICE);

  document.querySelectorAll('.summary-price').forEach(el => el.textContent = total.toLocaleString('en-IN'));
  document.querySelectorAll('.summary-total').forEach(el => el.textContent = total.toLocaleString('en-IN'));
  document.querySelector('.summary-dur').textContent = durLabels[dur];
  document.getElementById('btn-price').textContent = total.toLocaleString('en-IN');

  const discRow = document.getElementById('discount-row');
  if (dur !== 'monthly' && disc < 0) {
    discRow.style.display = 'flex';
    document.querySelector('.summary-discount').textContent = '-₹' + Math.abs(disc).toLocaleString('en-IN');
  } else {
    discRow.style.display = 'none';
  }
}

document.getElementById('pay-btn').addEventListener('click', async function() {
  // Validate form
  const form = document.getElementById('checkout-form');
  const inputs = form.querySelectorAll('[required]');
  let valid = true;
  inputs.forEach(inp => { if (!inp.value.trim()) { inp.style.borderColor = '#dc2626'; valid = false; } else { inp.style.borderColor = ''; } });
  if (!valid) { alert('Please fill all required fields.'); return; }

  const password  = document.querySelector('[name="password"]').value;
  const confirmed = document.querySelector('[name="password_confirmation"]').value;
  if (password !== confirmed) { alert('Passwords do not match.'); return; }
  if (password.length < 8) { alert('Password must be at least 8 characters.'); return; }

  this.disabled = true;
  this.textContent = 'Creating order...';

  const payload = {
    plan_id:       PLAN_ID,
    duration_type: currentDur,
    company_name:  document.getElementById('company_name').value,
    name:          document.getElementById('reg_name').value,
    email:         document.getElementById('reg_email').value,
    phone:         document.getElementById('reg_phone').value,
    password:      document.getElementById('reg_password').value,
    _token:        document.querySelector('[name="_token"]').value,
  };

  try {
    const res  = await fetch('{{ route("plans.order") }}', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': payload._token },
      body: JSON.stringify(payload),
    });
    const data = await res.json();

    if (!data.order_id) throw new Error(data.message || 'Failed to create order.');

    const rzp = new Razorpay({
      key:         RAZORPAY_KEY,
      amount:      data.amount,
      currency:    'INR',
      order_id:    data.order_id,
      name:        'WA SaaS Platform',
      description: data.plan_name + ' Plan — ' + data.duration,
      image:       '',
      prefill: {
        name:    document.getElementById('reg_name').value,
        email:   document.getElementById('reg_email').value,
        contact: document.getElementById('reg_phone').value,
      },
      theme: { color: '#1D9E75' },
      handler: async function(response) {
        // Verify payment server-side
        const vres = await fetch('{{ route("plans.verify") }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': payload._token },
          body: JSON.stringify({
            razorpay_order_id:   response.razorpay_order_id,
            razorpay_payment_id: response.razorpay_payment_id,
            razorpay_signature:  response.razorpay_signature,
          }),
        });
        const redirect = await vres.url || '{{ route("payment.success") }}';
        window.location.href = '{{ route("payment.success") }}';
      },
      modal: {
        ondismiss: () => {
          document.getElementById('pay-btn').disabled = false;
          document.getElementById('pay-btn').innerHTML = '🔒 Pay ₹<span id="btn-price">' + Math.round(BASE_PRICE * multipliers[currentDur]).toLocaleString('en-IN') + '</span> & Activate Account';
        }
      }
    });
    rzp.open();
  } catch (err) {
    alert('Error: ' + err.message);
    document.getElementById('pay-btn').disabled = false;
    document.getElementById('pay-btn').innerHTML = '🔒 Pay ₹<span id="btn-price">' + Math.round(BASE_PRICE * multipliers[currentDur]).toLocaleString('en-IN') + '</span> & Activate Account';
  }
});
</script>
@endpush

@endsection
