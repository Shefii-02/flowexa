<?php

// namespace App\Modules\Settings\DTOs;

// // ─── Update Company Settings ──────────────────────────────────────────────────
// readonly class UpdateSettingsDTO
// {
//     public function __construct(
//         public ?string $name     = null,
//         public ?string $email    = null,
//         public ?string $phone    = null,
//         public ?string $website  = null,
//         public ?array  $settings = null,  // timezone, language, otp_template, etc.
//     ) {}

//     public static function fromRequest(array $data): self
//     {
//         return new self(
//             name:     $data['name']     ?? null,
//             email:    $data['email']    ?? null,
//             phone:    $data['phone']    ?? null,
//             website:  $data['website']  ?? null,
//             settings: $data['settings'] ?? null,
//         );
//     }
// }

// // ─── WA Credentials ───────────────────────────────────────────────────────────
// readonly class WaCredentialsDTO
// {
//     public function __construct(
//         public string  $waPhoneId,
//         public string  $waAccessToken,
//         public ?string $waBusinessId = null,
//     ) {}

//     public static function fromRequest(array $data): self
//     {
//         return new self(
//             waPhoneId:     $data['wa_phone_id'],
//             waAccessToken: $data['wa_access_token'],
//             waBusinessId:  $data['wa_business_id'] ?? null,
//         );
//     }
// }

// // ─── SuperAdmin: Create Company ───────────────────────────────────────────────
// readonly class SuperAdminCreateCompanyDTO
// {
//     public function __construct(
//         public string $companyName,
//         public string $ownerName,
//         public string $ownerEmail,
//         public string $ownerPassword,
//         public int    $planId,
//         public int    $initialBalance = 1000,
//     ) {}

//     public static function fromRequest(array $data): self
//     {
//         return new self(
//             companyName:    $data['company_name'],
//             ownerName:      $data['owner_name'],
//             ownerEmail:     $data['owner_email'],
//             ownerPassword:  $data['owner_password'],
//             planId:         (int) $data['plan_id'],
//             initialBalance: (int) ($data['initial_balance'] ?? 1000),
//         );
//     }
// }

// // ─── SuperAdmin: Update Company Status ───────────────────────────────────────
// readonly class UpdateCompanyStatusDTO
// {
//     public function __construct(
//         public string $status, // active | suspended | trial
//     ) {}

//     public static function fromRequest(array $data): self
//     {
//         return new self(status: $data['status']);
//     }
// }

// // ─── SuperAdmin: Top-up Wallet ───────────────────────────────────────────────
// readonly class TopUpDTO
// {
//     public function __construct(
//         public int    $amount,
//         public string $description = 'Manual top-up by superadmin',
//     ) {}

//     public static function fromRequest(array $data): self
//     {
//         return new self(
//             amount:      (int) $data['amount'],
//             description: $data['description'] ?? 'Manual top-up by superadmin',
//         );
//     }
// }

// // ─── Message Log Filter ───────────────────────────────────────────────────────
// readonly class MessageLogFilterDTO
// {
//     public function __construct(
//         public ?string $direction = null,
//         public ?string $type      = null,
//         public ?string $status    = null,
//         public ?string $phone     = null,
//         public int     $perPage   = 30,
//         public int     $page      = 1,
//     ) {}

//     public static function fromRequest(array $data): self
//     {
//         return new self(
//             direction: $data['direction'] ?? null,
//             type:      $data['type']      ?? null,
//             status:    $data['status']    ?? null,
//             phone:     $data['phone']     ?? null,
//             perPage:   (int) ($data['per_page'] ?? 30),
//             page:      (int) ($data['page']     ?? 1),
//         );
//     }
// }
