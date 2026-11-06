<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramFlowRun extends Model
{
    use HasFactory;

    protected $table = 'instagram_flow_runs';
    protected $guarded = [];

    protected $casts = [
        'context'         => 'array',
        'wait_token'      => 'integer',
        'wait_expires_at' => 'datetime',
        'finished_at'     => 'datetime',
    ];

    public function flow(): BelongsTo { return $this->belongsTo(InstagramFlow::class, 'instagram_flow_id'); }
    public function account(): BelongsTo { return $this->belongsTo(InstagramAccount::class, 'instagram_account_id'); }
    public function conversation(): BelongsTo { return $this->belongsTo(InstagramConversation::class, 'instagram_conversation_id'); }

    public function ctx(string $key, mixed $default = null): mixed
    {
        return ($this->context ?? [])[$key] ?? $default;
    }

    public function setCtx(string $key, mixed $value): void
    {
        $this->context = array_merge($this->context ?? [], [$key => $value]);
    }
}
