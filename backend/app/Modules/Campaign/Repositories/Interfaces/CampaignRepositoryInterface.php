<?php

namespace App\Modules\Campaign\Repositories\Interfaces;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Modules\Campaign\DTOs\CampaignContactFilterDTO;
use App\Modules\Campaign\DTOs\CampaignFilterDTO;
use App\Modules\Campaign\DTOs\CreateCampaignDTO;
use App\Modules\Campaign\DTOs\UpdateCampaignDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface CampaignRepositoryInterface
{
    public function paginate(int $companyId, CampaignFilterDTO $filter): LengthAwarePaginator;

    public function findById(int $id, int $companyId): ?Campaign;

    public function create(int $companyId, int $userId, CreateCampaignDTO $dto): Campaign;

    public function update(Campaign $campaign, UpdateCampaignDTO $dto): Campaign;

    public function delete(Campaign $campaign): void;

    public function updateStatus(Campaign $campaign, string $status): Campaign;

    public function updateStats(int $campaignId, array $stats): void;

    public function insertContacts(int $campaignId, array $rows): void;

    public function clearPendingContacts(int $campaignId): void;

    public function paginateContacts(int $campaignId, CampaignContactFilterDTO $filter): LengthAwarePaginator;

    public function getPendingContacts(int $campaignId, int $limit): Collection;

    public function markContactSent(int $id, string $waMessageId): void;

    public function markContactDelivered(string $waMessageId): void;

    public function markContactRead(string $waMessageId): void;

    public function markContactFailed(int $id, string $reason): void;

    public function countByStatus(int $campaignId): array;

    public function resetFailedToPending(int $campaignId): int;

    public function resolveContactPhones(int $companyId, Campaign $campaign): Collection;
}
