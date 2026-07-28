<?php

namespace App\Modules\Analytics\Services;

use App\Modules\Analytics\Repositories\Interfaces\AnalyticsRepositoryInterface;

class AnalyticsService
{
    public function __construct(
        private readonly AnalyticsRepositoryInterface $analyticsRepository,
    ) {}

    public function overview(int $companyId): array  { return $this->analyticsRepository->overview($companyId); }
    public function campaigns(int $companyId): array  { return $this->analyticsRepository->campaigns($companyId); }
    public function flows(int $companyId): array      { return $this->analyticsRepository->flows($companyId); }
    public function staff(int $companyId): array      { return $this->analyticsRepository->staff($companyId); }
    public function wallet(int $companyId): array     { return $this->analyticsRepository->wallet($companyId); }
    public function leads(int $companyId): array      { return $this->analyticsRepository->leads($companyId); }
    public function messages(int $companyId): array   { return $this->analyticsRepository->messages($companyId); }
}
