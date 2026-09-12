<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAccountAccess extends Model
{
    const UPDATED_AT = null;

    /**
     * Recognized account_type values — kept in one place for validation + the UI.
     * wa_session/phone_number/instagram_account intentionally match Lead.origin_type
     * exactly (see config/lead_sources.php and CreateLeadDTO) so a lead's origin can be
     * checked against a user's allowed accounts directly, with no translation table.
     */
    public const TYPES = ['wa_session', 'phone_number', 'instagram_account', 'meta_ads_account'];

    protected $table = 'staff_account_access';

    protected $fillable = ['company_id', 'user_id', 'account_type', 'account_id', 'created_at'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }
}
