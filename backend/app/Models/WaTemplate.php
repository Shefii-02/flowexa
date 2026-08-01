<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// WaTemplate
// ════════════════════════════════════════════════════════════════════════════
class WaTemplate extends Model
{

    protected $fillable = [
        'company_id',
        'wa_phone_number_id',
        'name',
        'wa_template_id',
        'category',
        'language',
        'body',
        'body_examples',
        'header',
        'header_format',
        'header_handle',
        'header_sample_path',
        'header_sample_url',
        'header_example',
        'footer',
        'buttons',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'body_examples' => 'array',
        'buttons'       => 'array',
    ];

    // protected $fillable = [
    //     'company_id', 'name', 'wa_template_id', 'category',
    //     'language', 'body', 'header', 'footer', 'variables', 'status',
    // ];

    // protected $casts = ['variables' => 'array'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'template_id');
    }
    public function waPhoneNumber(): BelongsTo
    {
        return $this->belongsTo(WaPhoneNumber::class, 'wa_phone_number_id');
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }
}
