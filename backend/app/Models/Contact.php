<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


// ════════════════════════════════════════════════════════════════════════════
// Contact
// ════════════════════════════════════════════════════════════════════════════
class Contact extends Model
{
    use SoftDeletes;

    private static ?bool $aliasColumn = null;

    /** The aliases column exists (false in the moment between deploying this code and running the migration). */
    public static function aliasesAvailable(): bool
    {
        return self::$aliasColumn ??= \Illuminate\Support\Facades\Schema::hasColumn('contacts', 'phone_aliases');
    }

    public static function forgetAliasMemo(): void { self::$aliasColumn = null; }

    /** A contact of this company known by this phone — or by an identifier it used to have (e.g. the @lid digits it arrived with). */
    public static function findByPhone(int $companyId, string $phone): ?self
    {
        return static::where('company_id', $companyId)
            ->where(function ($q) use ($phone) {
                $q->where('phone', $phone);
                if (self::aliasesAvailable()) { $q->orWhereJsonContains('phone_aliases', $phone); }
            })
            ->orderByRaw('phone = ? desc', [$phone])   // an exact match wins over an alias
            ->first();
    }

    /** firstOrCreate by phone that also recognises an alias — use for anything created from an inbound chat. */
    public static function forPhone(int $companyId, string $phone, array $defaults = [], ?string $waId = null): self
    {
        $contact = static::findByPhone($companyId, $phone) ?? static::firstOrCreate(['company_id' => $companyId, 'phone' => $phone], $defaults);
        // keep the chat id the message really came from (e.g. 88630978687215@lid): for a privacy-id chat it is the only way back to the
        // conversation — the "phone" is just its digits. Set once, never overwritten.
        if ($waId !== null && $waId !== '' && ($contact->wa_id === null || $contact->wa_id === '') && strlen($waId) <= 30) {
            $contact->forceFill(['wa_id' => $waId])->saveQuietly();
        }
        return $contact;
    }

    /**
     * Does `phone` look like a dialable number? A WhatsApp privacy id ("123…@lid") is just digits too, but too long to be a
     * phone number (E.164 stops at 15 digits and 15 is in practice always an id). A hint for the UI — never a hard rule.
     */
    public function phoneLooksReal(): bool
    {
        $phone = (string) $this->phone;
        if (str_contains($phone, '@')) {
            return false;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        // the phone IS a privacy id's digits (a contact that has since been given its real number keeps the @lid chat id as wa_id)
        if (str_ends_with((string) $this->wa_id, '@lid') && preg_replace('/\D+/', '', (string) $this->wa_id) === $digits) {
            return false;
        }
        return strlen($digits) >= 7 && strlen($digits) <= 14;
    }

    protected $fillable = [
        'company_id', 'phone', 'phone_aliases', 'name', 'email', 'wa_id',
        'custom_fields', 'opted_in', 'opted_out_at',
        'last_message_at', 'crm_id',
        // AI / lead intelligence fields
        'lead_score', 'lead_score_updated_at', 'lead_stage',
        'last_sentiment', 'detected_intent',
        'buying_signals_count', 'objections_count',
        'meta_ai_profile', 'conversation_summary', 'summary_updated_at',
        // Assignment / lead-routing fields
        'current_assignment_id', 'total_leads_count', 'first_lead_at', 'last_lead_at',
    ];

    protected $casts = [
        'custom_fields'        => 'array',
        'phone_aliases'        => 'array',
        'opted_in'             => 'boolean',
        'opted_out_at'         => 'datetime',
        'last_message_at'      => 'datetime',
        'lead_score_updated_at'=> 'datetime',
        'summary_updated_at'   => 'datetime',
        'meta_ai_profile'      => 'array',
        'lead_score'           => 'integer',
        'buying_signals_count' => 'integer',
        'objections_count'     => 'integer',
        'total_leads_count'    => 'integer',
        'first_lead_at'        => 'datetime',
        'last_lead_at'         => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function company(): BelongsTo  { return $this->belongsTo(Company::class); }
    public function leads(): HasMany      { return $this->hasMany(Lead::class); }
    public function messages(): HasMany   { return $this->hasMany(MessageLog::class); }
    public function currentAssignment(): BelongsTo
    {
        return $this->belongsTo(LeadAssignment::class, 'current_assignment_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(ContactLabel::class, 'contact_label_pivot', 'contact_id', 'contact_label_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeOptedIn($q)   { return $q->where('opted_in', true); }
    public function scopeOptedOut($q)  { return $q->where('opted_in', false); }
    public function scopeForCompany($q, int $id) { return $q->where('company_id', $id); }
}
