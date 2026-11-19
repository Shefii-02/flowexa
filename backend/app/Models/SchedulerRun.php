<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchedulerRun extends Model
{
    public $timestamps = false;
    protected $fillable = ['task', 'expression', 'started_at', 'finished_at', 'duration_ms', 'status', 'exit_code', 'error'];
    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
}
