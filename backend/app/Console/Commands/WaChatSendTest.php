<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Services\OpenWaMessageService;
use App\Modules\WaChat\Services\WaChatTokenService;
use Illuminate\Console\Command;

class WaChatSendTest extends Command
{
    protected $signature = 'wa-chat:send-test
        {company : Company id or slug}
        {chatId : Recipient chat id, e.g. 9198XXXXXXXX@c.us}
        {text=Test message from wa-chat:send-test : Message text}
        {--session= : Session id to send through (defaults to the company\'s connected session, else its first)}
        {--no-sync : Skip the pre-send allowlist sync — useful to reproduce a 401 raw, before self-heal kicks in}';

    protected $description = 'Send one WhatsApp message straight through backend-node, bypassing the queue, to reproduce/diagnose auth or session issues directly';

    public function handle(WaChatTokenService $svc): int
    {
        $key = $this->argument('company');
        $company = Company::query()
            ->where('id', is_numeric($key) ? (int) $key : 0)
            ->orWhere('slug', $key)
            ->first();

        if (! $company) {
            $this->error("No company found for '{$key}'.");
            return self::FAILURE;
        }

        $apiKey = (string) ($company->wa_chat_token ?? '');
        if ($apiKey === '') {
            $this->error("Company #{$company->id} has no wa_chat_token — run `wa-chat:token {$company->id} --provision` first.");
            return self::FAILURE;
        }

        $sessions = WahaSession::where('company_id', $company->id)->get();
        if ($sessions->isEmpty()) {
            $this->error("Company #{$company->id} owns no sessions at all.");
            return self::FAILURE;
        }

        $sessionId = $this->option('session')
            ?: $sessions->firstWhere('status', 'connected')?->session_name
            ?: $sessions->first()->session_name;

        $this->line('Company        : ' . "#{$company->id} {$company->name}");
        $this->line('Gateway        : ' . $svc->baseUrl());
        $this->line('Key prefix     : ' . substr($apiKey, 0, 14) . '…');
        $this->line('Key id         : ' . ($company->wa_chat_key_id ?: '(EMPTY — syncSessions() is a no-op, see wa-chat:token --provision)'));
        $this->line('Session used   : ' . $sessionId);
        $this->line('Sessions owned : ' . $sessions->pluck('session_name')->implode(', '));
        $this->newLine();

        if (! $this->option('no-sync')) {
            $this->comment('Syncing allowlist before send (skip with --no-sync)...');
            if ($svc->syncSessions($company)) {
                $this->info('  synced.');
            } else {
                $this->error('  sync failed — the gateway rejected the push or was unreachable (see laravel.log). A 401 below is expected if so.');
            }
        }

        $wa  = new OpenWaMessageService();
        $res = $wa->sendText($sessionId, $apiKey, $this->argument('chatId'), $this->argument('text'));

        $this->newLine();
        $this->line('HTTP status: ' . $res->status());
        $this->line('Body       : ' . $res->body());

        if ($res->successful()) {
            $this->info('✅ Sent.');
            return self::SUCCESS;
        }

        if ($res->status() === 401) {
            $this->error('❌ 401 — the key itself, or its allowedSessions on the gateway, does not cover this session.');
            $this->comment('If --no-sync was NOT used and this still 401s, the mismatch is not a stale allowlist:');
            $this->comment('check that the session id above actually belongs to this company on the gateway.');
        } else {
            $this->error('❌ Send failed.');
        }

        return self::FAILURE;
    }
}
