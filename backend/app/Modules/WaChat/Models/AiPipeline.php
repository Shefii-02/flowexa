<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPipeline extends Model
{
    protected $fillable = [
        'company_id', 'name', 'description',
        'trigger_type', 'trigger_config', 'steps', 'is_active',
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'steps'          => 'array',
        'is_active'      => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AiPipelineRun::class, 'pipeline_id');
    }
}
