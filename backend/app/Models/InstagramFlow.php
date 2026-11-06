<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InstagramFlow extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'instagram_flows';
    protected $guarded = [];

    protected $casts = [
        'nodes'             => 'array',
        'edges'             => 'array',
        'is_active'         => 'boolean',
        'wizard'            => 'array',
        'priority'          => 'integer',
        'triggered_count'   => 'integer',
        'last_triggered_at' => 'datetime',
    ];

    public function account(): BelongsTo { return $this->belongsTo(InstagramAccount::class, 'instagram_account_id'); }
    public function runs(): HasMany { return $this->hasMany(InstagramFlowRun::class); }

    /** The single trigger node (`trigger_comment` / `trigger_dm`), or null on a malformed graph. */
    public function triggerNode(): ?array
    {
        foreach ($this->nodes ?? [] as $n) {
            if (str_starts_with((string) ($n['type'] ?? ''), 'trigger_')) {
                return $n;
            }
        }
        return null;
    }

    public function nodeById(string $id): ?array
    {
        foreach ($this->nodes ?? [] as $n) {
            if (($n['id'] ?? null) === $id) {
                return $n;
            }
        }
        return null;
    }

    /** Target node id reached from `$nodeId` through output `$handle` (null/"next" = the default output). */
    public function nextNodeId(string $nodeId, string $handle = 'next'): ?string
    {
        foreach ($this->edges ?? [] as $e) {
            if (($e['source'] ?? null) !== $nodeId) continue;
            if (($e['sourceHandle'] ?? 'next' ?: 'next') === $handle) {
                return $e['target'] ?? null;
            }
        }
        return null;
    }

    public function hasEdge(string $nodeId, string $handle): bool
    {
        return $this->nextNodeId($nodeId, $handle) !== null;
    }

    /**
     * Whether this flow's trigger node fires for the given text / media. Empty keywords = any (comment-like
     * triggers). `$mediaTime` lazily returns the media's publish time (for "next post/reel").
     */
    public function matchesTrigger(string $text, ?string $mediaId, ?callable $mediaTime = null): bool
    {
        $d = $this->triggerNode()['data'] ?? [];
        $scope = $d['media_scope'] ?? 'all';
        if ($scope === 'specific' || $scope === 'selected') {
            if ($mediaId === null || !in_array($mediaId, $d['media_ids'] ?? [], true)) return false;
        } elseif ($scope === 'next') {
            if (!empty($d['locked_media_id'])) {
                if ($mediaId !== $d['locked_media_id']) return false;
            } elseif ($mediaId === null || !$mediaTime || $mediaTime() < ($this->created_at?->timestamp ?? 0)) {
                return false;
            }
        }
        foreach (array_filter($d['exclude_keywords'] ?? [], fn ($k) => trim((string) $k) !== '') as $k) {
            if (mb_stripos($text, trim($k)) !== false) return false;
        }
        $keywords = array_values(array_filter($d['keywords'] ?? [], fn ($k) => trim((string) $k) !== ''));
        if (!$keywords) {
            return in_array($this->trigger_type, ['comment', 'live_comment', 'story_reply'], true);
        }
        return InstagramAutomation::keywordsMatch($keywords, (string) ($d['match_type'] ?? 'any'), $text);
    }
}
