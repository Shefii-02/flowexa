<?php

namespace App\Modules\Settings\DTOs;
readonly class SuperAdminCreateCompanyDTO
{
    public function __construct(
        public string $companyName,
        public string $ownerName,
        public ?string $ownerPhone,
        public ?string $companyPhone,
        public string $ownerEmail,
        public ?string $ownerPassword,
        public int    $planId,
        public int    $initialBalance = 1000,
        public string $businessType = 'generic',
        public ?string $companyEmail = null,
        public ?string $website = null,
        public string $status = 'active',
        public ?int   $trialDays = null,
        public ?int   $maxDevicesPerUser = null,
    ) {}


    public static function fromRequest(array $data): self
    {
        return new self(
            companyName:       $data['company_name'],
            ownerName:         $data['owner_name'],
            ownerEmail:        $data['owner_email'],
            ownerPhone:        $data['owner_phone'] ?? null,
            companyPhone:      $data['company_phone'] ?? null,
            ownerPassword:     $data['owner_password'] ?? null,
            planId:            (int) $data['plan_id'],
            initialBalance:    (int) ($data['initial_balance'] ?? 1000),
            businessType:      $data['business_type'] ?? 'generic',
            companyEmail:      $data['company_email'] ?? null,
            website:           $data['website'] ?? null,
            status:            $data['status'] ?? 'active',
            trialDays:         isset($data['trial_days']) ? (int) $data['trial_days'] : null,
            maxDevicesPerUser: isset($data['max_devices_per_user']) ? (int) $data['max_devices_per_user'] : null,
        );
    }
}
