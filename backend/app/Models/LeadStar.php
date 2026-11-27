<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One user's star on one lead (personal — see the lead_stars migration). */
class LeadStar extends Model
{
    public $timestamps = false;
    protected $fillable = ['company_id', 'user_id', 'lead_id'];
}
