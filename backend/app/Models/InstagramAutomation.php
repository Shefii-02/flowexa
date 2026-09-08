<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstagramAutomation extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'instagram_automations';
    protected $guarded = [];

    protected $casts = [
        'keywords'            => 'array',
        'media_ids'           => 'array',
        'reply_once_per_user' => 'boolean',
        'handoff_to_ai'       => 'boolean',
        'is_active'           => 'boolean',
        'priority'            => 'integer',
        'triggered_count'     => 'integer',
        'last_triggered_at'   => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
        'deleted_at'          => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function account(): BelongsTo { return $this->belongsTo(InstagramAccount::class, 'instagram_account_id'); }

    /** Whether this rule applies to the given media id. */
    public function coversMedia(?string $mediaId): bool
    {
        if ($this->media_scope !== 'selected') {
            return true;
        }
        return $mediaId !== null && in_array($mediaId, $this->media_ids ?? [], true);
    }

    /** Whether the comment/message text satisfies this rule's keyword match. */
    public function matches(string $text): bool
    {
        $haystack = mb_strtolower(trim($text));
        $needles  = array_filter(array_map(fn ($k) => mb_strtolower(trim((string) $k)), $this->keywords ?? []));
        if (!$needles) {
            return false;
        }

        return match ($this->match_type) {
            'exact' => in_array($haystack, $needles, true),
            'all'   => collect($needles)->every(fn ($n) => str_contains($haystack, $n)),
            default => collect($needles)->contains(fn ($n) => str_contains($haystack, $n)),
        };
    }
}
