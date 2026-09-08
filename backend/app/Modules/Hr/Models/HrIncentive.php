<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrIncentive extends Model
{
    protected $table = 'hr_incentives';

    protected $fillable = [
        'company_id', 'user_id', 'incentive_rule_id', 'source_type', 'source_id',
        'title', 'base_amount', 'amount', 'earned_on', 'status', 'payroll_item_id', 'approved_by', 'note',
    ];

    protected $casts = [
        'earned_on'   => 'date',
        'base_amount' => 'float',
        'amount'      => 'float',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function rule(): BelongsTo { return $this->belongsTo(HrIncentiveRule::class, 'incentive_rule_id'); }
}
