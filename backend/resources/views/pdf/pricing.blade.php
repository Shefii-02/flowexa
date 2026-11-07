<!doctype html>
<html><head><meta charset="utf-8"><title>Teamzo pricing</title>
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111827; }
  h1 { font-size: 20px; margin: 0 0 2px; color: #03045e; }
  h2 { font-size: 13px; margin: 22px 0 6px; color: #03045e; border-bottom: 2px solid #90e0ef; padding-bottom: 3px; }
  .muted { color: #6b7280; font-size: 9px; }
  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th { background: #03045e; color: #fff; text-align: left; padding: 5px 6px; font-size: 9px; }
  td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  tr:nth-child(even) td { background: #f8fbfd; }
  .r { text-align: right; } .c { text-align: center; }
  .price { font-weight: bold; white-space: nowrap; }
  .note { font-size: 8.5px; color: #6b7280; margin-top: 6px; }
</style></head><body>
@php
  $money = fn ($v) => $v === null ? '—' : 'INR ' . number_format($v, 0);
  $unl = fn ($v) => $v === null ? 'Unlimited' : number_format((int) $v);
@endphp

<h1>Teamzo pricing</h1>
<div class="muted">Generated {{ $generatedAt->format('d M Y, H:i') }} · prices in Indian rupees (INR)</div>

@if(in_array($scope, ['all', 'plans']))
  <h2>Plans</h2>
  <table>
    <tr><th>Plan</th>@if($cycle !== 'yearly')<th class="r">Per month</th>@endif @if($cycle !== 'monthly')<th class="r">Per year</th>@endif<th class="r">Messages</th><th class="r">Speed / min</th></tr>
    @foreach($plans as $p)
      <tr><td><b>{{ $p['name'] }}</b></td>
        @if($cycle !== 'yearly')<td class="r price">{{ $money($p['monthly']) }}</td>@endif
        @if($cycle !== 'monthly')<td class="r price">{{ $money($p['yearly']) }}</td>@endif
        <td class="r">{{ $unl($p['messages']) }}</td><td class="r">{{ $p['throttle'] }}</td></tr>
    @endforeach
  </table>

  <h2>What each plan includes</h2>
  <table>
    <tr><th>Feature limit</th>@foreach($plans as $p)<th class="c">{{ $p['name'] }}</th>@endforeach</tr>
    @foreach($groups as $g)
      <tr><td colspan="{{ count($plans) + 1 }}" style="background:#eef6f3;font-weight:bold">{{ $g['title'] }}</td></tr>
      @foreach($g['keys'] as $key)
        @php($row = collect($limitRows)->firstWhere('key', $key))
        <tr><td>{{ $row['label'] }}@if($row['monthly']) <span class="muted">(per month)</span>@endif</td>
          @foreach($plans as $p)<td class="c">{{ $unl($p['limits'][$key]['value'] ?? null) }}</td>@endforeach</tr>
      @endforeach
    @endforeach
  </table>
  <div class="note">“Unlimited” means the plan sets no cap for that item. Limits can be raised individually with add-ons.</div>
@endif

@if(in_array($scope, ['all', 'addons']))
  <h2>Add-ons — raise one limit without changing your plan</h2>
  @if(count($addons) === 0)
    <div class="muted">No add-ons are available right now.</div>
  @else
  <table>
    <tr><th>Add-on</th><th>Adds</th>@if($cycle !== 'yearly')<th class="r">Per month</th>@endif @if($cycle !== 'monthly')<th class="r">Per year</th>@endif<th class="r">One-time</th></tr>
    @php($lastGroup = null)
    @foreach($addons as $a)
      @if($a['group'] !== $lastGroup)<tr><td colspan="5" style="background:#eef6f3;font-weight:bold">{{ $a['group'] }}</td></tr>@php($lastGroup = $a['group'])@endif
      <tr><td><b>{{ $a['name'] }}</b>@if($a['description'])<br><span class="muted">{{ $a['description'] }}</span>@endif</td>
        <td>{{ $a['adds'] ?? ucfirst(str_replace('_', ' ', $a['type'])) }}</td>
        @if($cycle !== 'yearly')<td class="r price">{{ $money($a['monthly']) }}</td>@endif
        @if($cycle !== 'monthly')<td class="r price">{{ $money($a['yearly']) }}</td>@endif
        <td class="r price">{{ $money($a['one_time']) }}</td></tr>
    @endforeach
  </table>
  <div class="note">Add-ons last for the period purchased. When one ends, anything you already added stays; you simply can’t add more beyond your plan’s limit until you renew.</div>
  @endif
@endif
</body></html>
