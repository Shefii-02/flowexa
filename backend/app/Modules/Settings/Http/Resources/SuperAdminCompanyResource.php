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
            'status' => $this->status,
            'plan_id' => $this->plan_id,

            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
                'price' => $this->plan->price,
            ]),

            'wallet' => $this->whenLoaded('wallet', fn () => [
                'balance' => $this->wallet->balance,
                'is_low' => $this->wallet->is_low,
            ]),

            'company_owner' => $this->whenLoaded('companyOwner', fn () =>
                $this->companyOwner ? [
                    'id' => $this->companyOwner->id,
                    'name' => $this->companyOwner->name,
                    'email' => $this->companyOwner->email,
                    'phone' => $this->companyOwner->phone,
                ] : null
            ),

            'wa_connected' => $this->wa_connected,
        ];
    }
}
