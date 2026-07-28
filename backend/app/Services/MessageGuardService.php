<?php

namespace App\Services;

use App\Models\MessageBlacklist;
use App\Models\MessageDedupLog;
use Illuminate\Support\Facades\Log;

/**
 * MessageGuardService
 *
 * Used before sending any outbound message (campaign, flow, OTP).
 * Checks:
 *   1. Phone is not blacklisted for this company
 *   2. 24hr dedup: same number not already received a message today
 *
 * Returns: true if safe to send, false if should skip (no wallet deduct on block/dedup)
 */
class MessageGuardService
{
    /**
     * Check if safe to send + optionally record the send.
     *
     * @param  int     $companyId
     * @param  string  $phone
     * @param  bool    $record    — whether to record dedup log (false for dry-run checks)
     * @param  int|null $waPhoneNumberId
     * @return array{allowed: bool, reason: string|null}
     */
    public function check(int $companyId, string $phone, bool $record = false, ?int $waPhoneNumberId = null): array
    {
        // 1. Blacklist check
        if ($this->isBlacklisted($companyId, $phone)) {
            Log::info("MessageGuard: blocked number {$phone} (company {$companyId})");
            return ['allowed' => false, 'reason' => 'blacklisted'];
        }

        // 2. 24hr dedup check
        if (MessageDedupLog::alreadySentToday($companyId, $phone)) {
            Log::info("MessageGuard: dedup skip {$phone} (company {$companyId}) - already sent today");
            return ['allowed' => false, 'reason' => 'dedup_24hr'];
        }

        // Record send if allowed
        if ($record) {
            MessageDedupLog::recordSend($companyId, $phone, $waPhoneNumberId);
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Quick boolean check (does not record).
     */
    public function canSend(int $companyId, string $phone): bool
    {
        return $this->check($companyId, $phone, false)['allowed'];
    }

    /**
     * Check + record atomically.
     */
    public function checkAndRecord(int $companyId, string $phone, ?int $waPhoneNumberId = null): array
    {
        return $this->check($companyId, $phone, true, $waPhoneNumberId);
    }

    private function isBlacklisted(int $companyId, string $phone): bool
    {
        return MessageBlacklist::where('company_id', $companyId)
            ->where('phone', $phone)
            ->exists();
    }
}
