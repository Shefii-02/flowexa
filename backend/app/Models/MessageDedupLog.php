<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ════════════════════════════════════════════════════════════════════════════
// MessageDedupLog — 24hr per-number dedup
// ════════════════════════════════════════════════════════════════════════════
class MessageDedupLog extends Model
{
    protected $table    = 'message_dedup_log';
    protected $fillable = ['company_id','phone','wa_phone_number_id','sent_date','count'];

    protected $casts = ['sent_date' => 'date'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    /**
     * Check if phone was already messaged today for this company.
     */
    public static function alreadySentToday(int $companyId, string $phone): bool
    {
        return static::where('company_id', $companyId)
            ->where('phone', $phone)
            ->where('sent_date', today())
            ->exists();
    }

    /**
     * Record a send (insert or increment).
     */
    public static function recordSend(int $companyId, string $phone, ?int $phoneNumberId = null): void
    {
        $record = static::firstOrNew([
            'company_id' => $companyId,
            'phone'      => $phone,
            'sent_date'  => today(),
        ]);

        if ($record->exists) {
            $record->increment('count');
        } else {
            $record->wa_phone_number_id = $phoneNumberId;
            $record->count = 1;
            $record->save();
        }
    }
}
