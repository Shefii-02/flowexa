<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Modules\WaChat\Models\WaAuthMessage;
use Illuminate\Database\Seeder;

class WaOtpTemplateSeeder extends Seeder
{
    /**
     * Seeds OTP auth messages AND utility templates for all existing companies.
     * Safe to run multiple times — skips companies that already have templates.
     */
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $hasOtp = WaAuthMessage::where('company_id', $company->id)
                ->where('type', 'otp')->exists();

            if (!$hasOtp) {
                $defaults = WaAuthMessage::defaultTemplates($company->id, $company->name ?? 'Us');
                foreach ($defaults as $d) {
                    WaAuthMessage::create($d);
                }
                $this->command->info("OTP templates seeded for company: {$company->name}");
            }

            $hasUtility = WaAuthMessage::where('company_id', $company->id)
                ->whereIn('type', ['utility', 'payment_reminder', 'appointment'])
                ->exists();

            if (!$hasUtility) {
                $utils = WaAuthMessage::defaultUtilityTemplates($company->id, $company->name ?? 'Us');
                foreach ($utils as $d) {
                    WaAuthMessage::create($d);
                }
                $this->command->info("Utility templates seeded for company: {$company->name}");
            }
        });
    }
}
