<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Company;
use App\Models\User;

class WaExportJob extends Model
{
    protected $table = 'wa_export_jobs';

    protected $fillable = [
        'company_id', 'created_by', 'export_type', 'session_id',
        'filters', 'status', 'file_url', 'row_count', 'error_message',
    ];

    protected $casts = ['filters' => 'array'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
