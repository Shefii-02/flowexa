<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WidgetConversation extends Model
{
    use HasFactory;

    protected $table = 'widget_conversations';
    protected $guarded = [];

    protected $casts = [
        'collected'           => 'array',
        'matched_listing_ids' => 'array',
        'qualified_at'        => 'datetime',
        'last_message_at'     => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    public function widget(): BelongsTo { return $this->belongsTo(ChatWidget::class, 'chat_widget_id'); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
    public function messages(): HasMany { return $this->hasMany(WidgetMessage::class)->orderBy('created_at'); }
}
