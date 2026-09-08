<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatWidget extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'chat_widgets';
    protected $guarded = [];

    protected $casts = [
        'is_active'            => 'boolean',
        'branding'             => 'array',
        'allowed_origins'      => 'array',
        'notify_emails'        => 'array',
        'notify_whatsapp'      => 'array',
        'conversations_count'  => 'integer',
        'leads_count'          => 'integer',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
        'deleted_at'           => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChatWidget $w) {
            $w->public_key ??= 'wgt_' . Str::random(28);
        });
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function conversations(): HasMany { return $this->hasMany(WidgetConversation::class); }

    /** True when $origin (a scheme://host[:port]) is allowed to run this widget. */
    public function originAllowed(?string $origin): bool
    {
        $allowed = $this->allowed_origins ?? [];
        if (empty($allowed)) {
            return true; // not locked down
        }
        if (!$origin) {
            return false;
        }
        $host = parse_url($origin, PHP_URL_HOST) ?: $origin;
        foreach ($allowed as $entry) {
            $e = parse_url($entry, PHP_URL_HOST) ?: $entry;
            if (strcasecmp($host, $e) === 0) {
                return true;
            }
        }
        return false;
    }

    public function resolvedTemplate(): string
    {
        return $this->industry_template ?: ($this->company?->industry_template ?: 'generic');
    }
}
