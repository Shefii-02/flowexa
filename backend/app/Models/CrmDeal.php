<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmDeal extends Model
{
    protected $table = 'crm_deals';

    public const STAGES = ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

    protected $fillable = [
        'company_id', 'contact_id', 'owner_id', 'incentive_rule_id', 'title', 'value', 'currency',
        'stage', 'status', 'expected_close_date', 'source', 'lost_reason', 'notes',
        'sort_order', 'closed_at',
    ];

    protected $casts = [
        'value'               => 'decimal:2',
        'expected_close_date' => 'date',
        'closed_at'           => 'datetime',
        'sort_order'          => 'integer',
    ];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function tasks(): HasMany { return $this->hasMany(CrmTask::class, 'deal_id'); }

    /** Keep status in step with the stage. */
    public static function statusForStage(string $stage): string
    {
        return match ($stage) {
            'won'   => 'won',
            'lost'  => 'lost',
            default => 'open',
        };
    }
}
