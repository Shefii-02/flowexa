<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// MessageLog
// ════════════════════════════════════════════════════════════════════════════
class MessageLog extends Model
{
    protected $fillable = [
        'company_id', 'contact_id', 'wa_message_id', 'direction', 'channel',
        'type', 'phone', 'content', 'status', 'lead_created', 'cost', 'delivered_at', 'read_at',
        'source', 'reference_id', 'reference_name', 'category', 'failure_reason', 'sent_at', 'retry_after', 'campaign_contact_id',
    ];

    protected $casts = [
        'content'      => 'array',
        'lead_created' => 'boolean',
        'delivered_at' => 'datetime',
        'read_at'      => 'datetime',
        'sent_at'      => 'datetime',
        'retry_after'  => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }

    /**
     * Record one outbound WA Cloud message (test send, campaign, API service, inbox reply…).
     * Never throws — an audit-log failure must not break the actual send.
     *
     * @param array<string,mixed>|string $content plain text (or an array with a 'body' key)
     */
    public static function recordCloudOutbound(
        int $companyId,
        string $phone,
        string $type,
        array|string $content,
        string $source,
        ?string $waMessageId,
        ?string $error = null,
        ?string $referenceId = null,
        ?string $referenceName = null,
        ?string $category = null,
        ?\DateTimeInterface $retryAfter = null,
        ?int $campaignContactId = null,
    ): void {
        try {
            // A campaign contact keeps ONE log row for its whole life: a retry updates it in place.
            $match = $campaignContactId ? ['campaign_contact_id' => $campaignContactId] : null;
            $values = [
                'company_id'     => $companyId,
                'wa_message_id'  => $waMessageId ?: null,
                'direction'      => 'outbound',
                'channel'        => 'wa_cloud',
                'source'         => $source,
                'reference_id'   => $referenceId,
                'reference_name' => $referenceName,
                'category'       => $category ?? ($type === 'template' ? null : 'service'),
                'sent_at'        => $error === null ? now() : null,
                'retry_after'    => $retryAfter,
                'type'           => $type,
                'phone'          => $phone,
                'content'        => is_array($content) ? (string) ($content['body'] ?? json_encode($content)) : $content,
                'status'         => $error === null ? 'sent' : 'failed',
                'failure_reason' => $error !== null ? mb_substr($error, 0, 500) : null,
                'cost'           => $error === null ? 1 : 0,
                'delivered_at'   => null,
                'read_at'        => null,
            ];
            $match ? static::updateOrCreate($match, $values) : static::create($values);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MessageLog write failed: ' . $e->getMessage());
        }
    }

    public function scopeInbound($q)  { return $q->where('direction', 'inbound'); }
    public function scopeOutbound($q) { return $q->where('direction', 'outbound'); }
    public function scopeDelivered($q){ return $q->where('status', 'delivered'); }
    public function scopeRead($q)     { return $q->where('status', 'read'); }
}
