<?php

namespace App\Modules\Lead\Repositories\Interfaces;

use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\User;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Modules\Lead\DTOs\ImportLeadDTO;
use App\Modules\Lead\DTOs\LeadFilterDTO;
use App\Modules\Lead\DTOs\UpdateLeadDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface LeadRepositoryInterface
{
    public function paginate(int $companyId, int $userId, bool $viewAll, LeadFilterDTO $filter): LengthAwarePaginator;
    public function findById(int $id, int $companyId): ?Lead;
    public function findByContact(int $contactId, int $companyId, string $category = null): ?Lead;
    public function create(int $companyId, CreateLeadDTO $dto): Lead;
    public function update(Lead $lead, UpdateLeadDTO $dto): Lead;
    public function assign(Lead $lead, int $assignedTo, int $assignedBy): Lead;
    public function delete(Lead $lead): void;
    public function logEvent(Lead $lead, string $event, array $payload): LeadEvent;
    public function findCounsellor(int $userId, int $companyId): ?User;
    public function countActiveLeadsFor(int $userId): int;
    public function analytics(int $companyId): array;
    public function pushCrmOutbox(Lead $lead, string $event): void;
    public function exportAll(int $companyId, LeadFilterDTO $filter): Collection;
    public function import(int $companyId, int $userId, ImportLeadDTO $dto): \App\Models\LeadImport;
}
