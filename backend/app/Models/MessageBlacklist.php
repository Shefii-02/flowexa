<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


// ════════════════════════════════════════════════════════════════════════════
// MessageBlacklist
// ════════════════════════════════════════════════════════════════════════════
class MessageBlacklist extends Model
{
    protected $table    = 'message_blacklist';
    protected $fillable = ['company_id','phone','reason','created_by'];

    public function company(): BelongsTo   { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo   { return $this->belongsTo(User::class,'created_by'); }
}
