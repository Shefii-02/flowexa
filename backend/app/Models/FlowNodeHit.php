<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer reaching a flow node that carries a lead category (see the flow_node_hits migration). */
class FlowNodeHit extends Model
{
    protected $fillable = [
        'company_id', 'flow_builder_id', 'flow_node_id', 'contact_id', 'lead_id', 'created_lead',
        'category', 'node_title', 'phone', 'contact_name', 'clicked_at',
    ];

    protected $casts = ['created_lead' => 'boolean', 'clicked_at' => 'datetime'];

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function node(): BelongsTo    { return $this->belongsTo(FlowNode::class, 'flow_node_id'); }
}
