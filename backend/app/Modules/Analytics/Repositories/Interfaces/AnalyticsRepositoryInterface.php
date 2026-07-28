<?php

namespace App\Modules\Analytics\Repositories\Interfaces;

interface AnalyticsRepositoryInterface
{
    public function overview(int $companyId): array;
    public function campaigns(int $companyId): array;
    public function flows(int $companyId): array;
    public function staff(int $companyId): array;
    public function wallet(int $companyId): array;
    public function leads(int $companyId): array;
    public function messages(int $companyId): array;
}
