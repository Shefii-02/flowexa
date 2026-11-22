<?php

namespace App\Modules\WaChat\Console\Commands;

use App\Models\Company;
use App\Models\MessageLog;
use App\Models\Wallet;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Services\WaChatBilling;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "I sent a WA Chat API message and the wallet was not charged" — shows, for one company, whether billing is on, what it costs, and what
 * happened to the latest billable messages (waiting for delivery, charged, delivered with an empty wallet, or never registered).
 *
 *   php artisan wa-chat:billing-check 12
 */
class CheckWaChatBilling extends Command
{
    protected $signature = 'wa-chat:billing-check {company : company id}';
    protected $description = 'Explain why WA Chat campaign / API / test messages were or were not charged to the wallet';

    public function handle(WaChatBilling $billing): int
    {
        if (!Schema::hasTable('wa_chat_charges') || !Schema::hasColumn('companies', 'wa_chat_billing')) {
            $this->error('The billing migration has not run (2026_11_21_000001). Run: php artisan migrate');
            return self::FAILURE;
        }
        $company = Company::with('plan')->find($this->argument('company'));
        if (!$company) { $this->error('No such company.'); return self::FAILURE; }

        $rate    = $billing->creditsPerMessage($company);
        $balance = (int) (Wallet::where('company_id', $company->id)->value('balance') ?? 0);
        $this->info("{$company->name} (#{$company->id})");
        $this->line('  WA Chat billing : ' . ($company->wa_chat_billing ?? 'wallet') . ($company->wa_chat_billing === 'included' ? '   ← messages are NOT charged. Superadmin → company → WA Chat → "Message billing" = Wallet.' : ''));
        $this->line("  Credits/message : {$rate} (plan " . ($company->plan?->name ?? '—') . ' rate ' . $billing->planRate($company) . ($company->wa_chat_credits_per_message !== null ? ", company override {$company->wa_chat_credits_per_message}" : '') . ')' . ($rate === 0 ? '   ← 0 = free, nothing is charged' : ''));
        $this->line("  Wallet balance  : {$balance}");
        $this->line('  Billing active  : ' . ($billing->bills($company) ? 'yes' : 'NO'));

        $this->newLine();
        $rows = DB::table('wa_chat_charges')->where('company_id', $company->id)->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('source, status, count(*) c, sum(credits) credits')->groupBy('source', 'status')->get();
        $this->line('Last 7 days (registered billable messages):');
        if ($rows->isEmpty()) { $this->line('  none — no campaign / API / test message was registered for billing.'); }
        foreach ($rows as $r) { $this->line(sprintf('  %-9s %-9s %4d message(s), %d credit(s)', $r->source, $r->status, $r->c, $r->credits)); }

        $stuck = DB::table('wa_chat_charges')->where('company_id', $company->id)->where('status', 'pending')->where('created_at', '<', now()->subMinutes(15))->count();
        if ($stuck) { $this->warn("  {$stuck} message(s) sent over 15 minutes ago are still waiting for WhatsApp to confirm delivery — the phone may be offline, or the delivery webhook is not reaching Laravel."); }

        $this->newLine();
        $this->line('Latest billable messages (campaign / API / test) registered for a charge:');
        $recent = DB::table('wa_chat_charges')->where('company_id', $company->id)->latest('id')->limit(8)->get();
        foreach ($recent as $c) { $this->line(sprintf('  %s  %-14s %-8s %s', \Illuminate\Support\Carbon::parse($c->created_at)->format('d M H:i'), $c->phone ?? '—', $c->source, $c->status === 'charged' ? "charged {$c->credits} credit(s)" : ($c->status === 'pending' ? 'waiting for delivery' : $c->status))); }
        if ($recent->isEmpty()) { $this->line('  none — the last API / campaign / test message was never registered (billing off, rate 0, or it was sent before this update).'); }

        $this->newLine();
        $this->line('Latest outgoing messages seen by the webhook (normal chat messages are free and never appear above):');
        $logs = MessageLog::where('company_id', $company->id)->where('channel', 'wa_chat')->where('direction', 'outbound')->latest('id')->limit(8)->get();
        foreach ($logs as $l) {
            $c = DB::table('wa_chat_charges')->where('company_id', $company->id)->where('wa_message_id', $l->wa_message_id)->first();
            $state = $c ? "{$c->source} · {$c->status}" . ($c->status === 'charged' ? " · {$c->credits} credit(s)" : '') : 'not registered (normal chat message, billing off, or registration failed)';
            $this->line(sprintf('  %s  %-14s %-10s %s', $l->created_at?->format('d M H:i'), $l->phone, $l->status ?? '—', $state));
        }
        if ($logs->isEmpty()) { $this->line('  none logged (a session that exists only on the gateway is not logged here; billing does not depend on this list).'); }

        $this->newLine();
        $this->line('Sessions:');
        foreach (DB::table('openwa_otp_services')->where('company_id', $company->id)->pluck('session_id')->filter()->unique() as $sid) {
            $known = WahaSession::where('company_id', $company->id)->where('session_name', $sid)->exists();
            $this->line("  API service session {$sid}: " . ($known ? 'known' : 'not in openwa_sessions (created on the gateway) — billing still works, the company is passed explicitly'));
        }
        return self::SUCCESS;
    }
}
