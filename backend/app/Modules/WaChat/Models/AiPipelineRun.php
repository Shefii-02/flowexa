<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPipelineRun extends Model
{
    protected $fillable = [
        'pipeline_id', 'company_id', 'triggered_by', 'trigger_data',
        'status', 'steps_log', 'result', 'error_message',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'trigger_data' => 'array',
        'steps_log'    => 'array',
        'result'       => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(AiPipeline::class, 'pipeline_id');
    }
}
