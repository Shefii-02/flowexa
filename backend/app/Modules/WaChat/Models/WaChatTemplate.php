<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Company;
use App\Models\User;

class WaChatTemplate extends Model
{
    protected $table = 'wa_chat_templates';

    protected $fillable = [
        'company_id', 'name', 'category', 'language',
        'header_type', 'header_content', 'body', 'footer',
        'buttons', 'media_blocks', 'status', 'created_by',
    ];

    protected $casts = ['buttons' => 'array', 'media_blocks' => 'array'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
