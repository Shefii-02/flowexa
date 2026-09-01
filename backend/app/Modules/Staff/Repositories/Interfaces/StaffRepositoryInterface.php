<?php

namespace App\Modules\Staff\Repositories\Interfaces;

use App\Models\Role;
use App\Models\User;
use App\Modules\Staff\DTOs\CreateStaffDTO;
use App\Modules\Staff\DTOs\ResetPasswordDTO;
use App\Modules\Staff\DTOs\StaffFilterDTO;
use App\Modules\Staff\DTOs\UpdateStaffDTO;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface StaffRepositoryInterface
{
    public function paginate(int $companyId, StaffFilterDTO $filter): LengthAwarePaginator;

    public function findById(int $id, int $companyId): ?User;

    public function create(int $companyId, CreateStaffDTO $dto): User;

    public function update(User $user, UpdateStaffDTO $dto): User;

    public function toggleActive(User $user): User;

    public function resetPassword(User $user, ResetPasswordDTO $dto): void;

    public function delete(User $user): void;

    public function getPerformance(int $companyId): Collection;

    public function getDepartments(int $companyId): Collection;

    public function getRoles(?int $companyId = null): Collection;

    public function findRole(int $roleId): ?Role;

    public function countActiveLeads(int $userId): int;

    public function unassignLeads(int $userId): void;
}
