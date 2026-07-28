<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'slug'          => $this->slug,
            'app_id'        => $this->app_id,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'website'       => $this->website,
            'logo'          => $this->logo,
            'status'        => $this->status,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'settings'      => $this->settings,
            'created_at'    => $this->created_at->toIso8601String(),

            // WA connected status (never expose token)
            'wa_connected' => !empty($this->wa_phone_id),
            'wa_phone_id'  => $this->wa_phone_id,

            'plan' => $this->whenLoaded('plan', fn() => [
                'id'             => $this->plan->id,
                'name'           => $this->plan->name,
                'messages_limit' => $this->plan->messages_limit,
                'features'       => $this->plan->features,
                'price'          => $this->plan->price,
            ]),

            'wallet' => $this->whenLoaded('wallet', fn() => [
                'balance'             => $this->wallet->balance,
                'total_used'          => $this->wallet->total_used,
                'total_purchased'     => $this->wallet->total_purchased,
                'low_balance_alert'   => $this->wallet->low_balance_alert,
                'auto_recharge'       => $this->wallet->auto_recharge,
                'is_low'              => $this->wallet->balance <= $this->wallet->low_balance_alert,
            ]),
        ];
    }
}
