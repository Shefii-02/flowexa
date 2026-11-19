<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// Lead
// ════════════════════════════════════════════════════════════════════════════
class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'contact_id', 'assigned_to', 'assigned_by',
        'flow_node_id', 'campaign_id', 'wa_open_campaign_id', 'stage', 'priority', 'category',
        'source', 'origin_type', 'origin_id', 'origin_label',
        'notes', 'crm_id', 'followed_up_at', 'enrolled_at', 'lost_at', 'assigned_at',
        'listing_id', 'sale_value', 'cost', 'verified_at', 'verified_by',
    ];

    protected $casts = [
        'followed_up_at' => 'datetime',
        'enrolled_at'    => 'datetime',
        'lost_at'        => 'datetime',
        'assigned_at'    => 'datetime',
        'verified_at'    => 'datetime',
        'sale_value'     => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function company(): BelongsTo    { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo    { return $this->belongsTo(Contact::class); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
    public function flowNode(): BelongsTo   { return $this->belongsTo(FlowNode::class, 'flow_node_id'); }
    public function campaign(): BelongsTo   { return $this->belongsTo(Campaign::class); }
    /** The open-wa bulk send (message_sender_jobs) this lead was attributed to via a reply — see WaChatLeadAttributionService. */
    public function waOpenCampaign(): BelongsTo { return $this->belongsTo(\App\Modules\WaChat\Models\MessageSenderJob::class, 'wa_open_campaign_id'); }
    public function listing(): BelongsTo    { return $this->belongsTo(Listing::class); }
    public function sale(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(ListingSale::class); }
    /** Every lead (this one included) for the same contact — used for the "repeat enquiry" count. */
    public function siblings(): HasMany    { return $this->hasMany(Lead::class, 'contact_id', 'contact_id'); }
    public function events(): HasMany       { return $this->hasMany(LeadEvent::class)->latest(); }
    /** Routing/notification history from the assignment engine — see LeadAssignment::lead_id. */
    public function verifiedBy(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
    private static ?bool $linksTable = null;

    /** The multi-category table exists (false for the moment between deploying this code and running the migration — nothing may break then). */
    public static function forgetLinksMemo(): void { self::$linksTable = null; }

    public static function linksAvailable(): bool
    {
        return self::$linksTable ??= \Illuminate\Support\Facades\Schema::hasTable('lead_category_links');
    }

    public function categoryLinks(): HasMany { return $this->hasMany(LeadCategoryLink::class)->orderBy('id'); }

    /** @return string[] every category of this lead — the primary (leads.category) first */
    public function categoryNames(): array
    {
        if (!$this->exists || !self::linksAvailable()) {
            return $this->category ? [$this->category] : [];
        }
        $names = $this->relationLoaded('categoryLinks') ? $this->categoryLinks->pluck('name')->all() : $this->categoryLinks()->pluck('name')->all();
        if ($this->category) {
            $names = array_merge([$this->category], array_values(array_filter($names, fn ($n) => mb_strtolower($n) !== mb_strtolower($this->category))));
        }
        return array_values(array_unique($names));
    }

    /** Leads that have this category among their categories (primary or not). */
    public function scopeInCategory($query, string $name)
    {
        if (!self::linksAvailable()) {
            return $query->where('leads.category', $name);
        }
        return $query->whereExists(fn ($q) => $q->selectRaw('1')->from('lead_category_links')
            ->whereColumn('lead_category_links.lead_id', 'leads.id')->where('lead_category_links.name', $name));
    }

    /**
     * Keep lead_category_links in step with the single-category writes the rest of the app still does (flows, imports, the
     * edit form, create): setting leads.category to X makes X one of the lead's categories, and swaps out the old primary.
     */
    protected static function booted(): void
    {
        static::saved(function (Lead $lead) {
            $created = $lead->wasRecentlyCreated;
            if (!self::linksAvailable() || (!$created && !$lead->wasChanged('category'))) {
                return;
            }
            $old = $created ? null : $lead->getOriginal('category');
            $new = $lead->category !== null && trim((string) $lead->category) !== '' ? trim((string) $lead->category) : null;
            if ($old !== null && $old !== $new) {
                LeadCategoryLink::where('lead_id', $lead->id)->where('name', $old)->delete();
            }
            if ($new !== null) {
                LeadCategoryLink::firstOrCreate(['lead_id' => $lead->id, 'name' => $new], ['company_id' => $lead->company_id]);
            }
        });
    }

    public function assignments(): HasMany  { return $this->hasMany(LeadAssignment::class); }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeActive($q)     { return $q->whereNotIn('stage', ['enrolled', 'lost']); }
    public function scopeEnrolled($q)   { return $q->where('stage', 'enrolled'); }
    public function scopeUnassigned($q) { return $q->whereNull('assigned_to'); }
    public function scopeForUser($q, int $userId) { return $q->where('assigned_to', $userId); }

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function isActive(): bool   { return !in_array($this->stage, ['enrolled', 'lost']); }
    public function isEnrolled(): bool { return $this->stage === 'enrolled'; }
    public function isLost(): bool     { return $this->stage === 'lost'; }

    /** When this lead reached a terminal stage, whichever outcome — used by the "closed date" filter. */
    public function getClosedAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->enrolled_at ?? $this->lost_at;
    }

    /** Display label for `source` from config/lead_sources.php — falls back to the raw slug. */
    public function getSourceLabelAttribute(): string
    {
        return config("lead_sources.{$this->source}.label") ?? str_replace('_', ' ', (string) $this->source);
    }

    /**
     * Account-scoped visibility widening: origin_type => allowed origin_id list, for
     * every origin type the given user is actually restricted on (StaffAccountAccess).
     * A type the user is unrestricted for is simply absent from the result — used
     * alongside "assigned_to = user" so a restricted user also sees unassigned/shared
     * leads that came in through a WA session, WA Cloud number, or Instagram account
     * they've been explicitly granted access to.
     */
    public static function scopedOriginAccessFor(User $user): array
    {
        $result = [];
        foreach (['wa_session', 'phone_number', 'instagram_account'] as $type) {
            $ids = $user->allowedAccountIds($type);
            if ($ids !== null) {
                $result[$type] = $ids;
            }
        }
        return $result;
    }

    /** @param array<string,array<int>> $scopedOriginAccess from scopedOriginAccessFor() */
    public function isVisibleByOrigin(array $scopedOriginAccess): bool
    {
        if (!$this->origin_type || !isset($scopedOriginAccess[$this->origin_type])) {
            return false;
        }
        return in_array($this->origin_id, $scopedOriginAccess[$this->origin_type], true);
    }
}


