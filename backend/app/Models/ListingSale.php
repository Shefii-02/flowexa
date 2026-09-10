<?php

namespace App\Models;

use App\Modules\Hr\Models\HrIncentive;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A recorded sale / admission / order of a catalog item, credited to one staff
 * member. Recording a sale posts an HrIncentive (see Hr\Support\SalesService).
 */
class ListingSale extends Model
{
    use SoftDeletes;

    protected $table = 'listing_sales';

    protected $fillable = [
        'company_id', 'listing_id', 'lead_id', 'contact_id', 'staff_id',
        'item_label', 'amount', 'currency', 'sold_at', 'incentive_id', 'note', 'created_by',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'sold_at' => 'date',
    ];

    public function company(): BelongsTo  { return $this->belongsTo(Company::class); }
    public function listing(): BelongsTo  { return $this->belongsTo(Listing::class); }
    public function lead(): BelongsTo     { return $this->belongsTo(Lead::class); }
    public function contact(): BelongsTo  { return $this->belongsTo(Contact::class); }
    public function staff(): BelongsTo    { return $this->belongsTo(User::class, 'staff_id'); }
    public function creator(): BelongsTo  { return $this->belongsTo(User::class, 'created_by'); }
    public function incentive(): BelongsTo { return $this->belongsTo(HrIncentive::class, 'incentive_id'); }
}
