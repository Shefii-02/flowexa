<?php

namespace App\Modules\WaCloud\Models;

use Illuminate\Database\Eloquent\Model;

class WaCloudRouteEvent extends Model
{
    protected $table = 'wa_cloud_route_events';
    public $timestamps = false;

    protected $fillable = [
        'company_id', 'route_id', 'wa_phone_number_id', 'phone', 'response_type', 'final_type', 'outcome', 'assigned_to', 'detail', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];
}
