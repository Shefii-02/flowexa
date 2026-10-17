<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One counted click: a given IP is counted once per link per day. */
class ShortLinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = ['short_link_id', 'click_date', 'ip', 'device', 'referrer', 'user_agent', 'created_at'];
}
