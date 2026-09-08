<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A short-lived QR / PIN login challenge. Generated on the web, consumed by the
 * mobile app. @see database/migrations/2026_09_08_000015_create_device_login_tables.php
 */
class DeviceLoginToken extends Model
{
    /** Seconds a challenge stays valid (matches the 45s web refresh). */
    public const TTL_SECONDS = 45;

    protected $fillable = [
        'company_id', 'user_id', 'created_by', 'token', 'pin', 'status',
        'expires_at', 'claimed_device_id', 'claimed_at', 'ip', 'user_agent',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    protected $hidden = ['token'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public static function issue(User $user, ?int $createdBy, ?string $ip = null, ?string $ua = null): self
    {
        return static::create([
            'company_id' => $user->company_id,
            'user_id'    => $user->id,
            'created_by' => $createdBy,
            'token'      => Str::lower(Str::random(40)),
            'pin'        => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'status'     => 'pending',
            'expires_at' => Carbon::now()->addSeconds(self::TTL_SECONDS),
            'ip'         => $ip,
            'user_agent' => $ua ? substr($ua, 0, 255) : null,
        ]);
    }

    /** The value the phone scans out of the QR. */
    public function qrPayload(): array
    {
        return ['v' => 1, 't' => $this->token];
    }
}
