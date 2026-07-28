<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


// ════════════════════════════════════════════════════════════════════════════
// LeadImport
// ════════════════════════════════════════════════════════════════════════════
class LeadImport extends Model
{
    protected $fillable = [
        'company_id','user_id','file_path','status',
        'total','imported','skipped','failed','errors',
    ];

    protected $casts = ['errors' => 'array'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
}
