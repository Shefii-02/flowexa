<?php

namespace App\Modules\Settings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuperAdminCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'status' => $this->status,
            'plan_id' => $this->plan_id,
            'app_id' => $this->app_id,
            'max_devices_per_user' => $this->max_devices_per_user,
            'max_web_devices_per_user' => $this->max_web_devices_per_user ?? 3,
            'industry_template' => $this->industry_template,
            'trial_ends_at' => $this->trial_ends_at,
            'created_at' => $this->created_at,

            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'price' => $this->plan->price,
            ]),

            'wallet' => $this->whenLoaded('wallet', fn () => [
                'balance' => $this->wallet->balance,
                'is_low' => $this->wallet->is_low,
            ]),

            // The owner-role user; a company whose owner has a different role name still shows its first user, so the edit form is never blank.
            'company_owner' => $this->whenLoaded('companyOwner', function () {
                $o = $this->companyOwner ?? $this->users()->orderBy('id')->first();
                return $o ? ['id' => $o->id, 'name' => $o->name, 'email' => $o->email, 'phone' => $o->phone] : null;
            }),
            'plan_expires_at' => $this->plan_expires_at,

            'wa_connected' => $this->wa_connected,
        ];
    }
}
