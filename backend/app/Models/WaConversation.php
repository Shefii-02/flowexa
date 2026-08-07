<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'wa_phone_number_id',
        'contact_id',
        'phone',
        'contact_name',
        'assigned_to',
        'status',           // open | pending | closed
        'last_message_at',
        'unread_count',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'unread_count'    => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function waPhoneNumber(): BelongsTo
    {
        return $this->belongsTo(WaPhoneNumber::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    // The staff member currently responsible for this conversation, if claimed
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WaMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function latestMessage(): HasMany
    {
        return $this->messages()->latest();
    }
}
