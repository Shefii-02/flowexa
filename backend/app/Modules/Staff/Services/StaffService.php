<?php

namespace App\Modules\Staff\Services;

use App\Models\User;
use App\Modules\Staff\DTOs\CreateStaffDTO;
use App\Modules\Staff\DTOs\ResetPasswordDTO;
use App\Modules\Staff\DTOs\StaffFilterDTO;
use App\Modules\Staff\DTOs\UpdateStaffDTO;
use App\Modules\Staff\Exceptions\StaffException;
use App\Modules\Staff\Repositories\Interfaces\StaffRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class StaffService
{
    // Protected role names — cannot be assigned by company admins
    private const PROTECTED_ROLES = ['superadmin', 'owner'];

    public function __construct(
        private readonly StaffRepositoryInterface $staffRepository,
    ) {}

    // ─── List paginated staff ─────────────────────────────────────────────────
    public function list(int $companyId, StaffFilterDTO $filter): LengthAwarePaginator
    {
        return $this->staffRepository->paginate($companyId, $filter);
    }

    // ─── Show single staff member ─────────────────────────────────────────────
    public function show(int $staffId, int $companyId): User
    {
        $user = $this->staffRepository->findById($staffId, $companyId);

        if (!$user) {
            throw StaffException::notFound();
        }

        return $user;
    }

    // ─── Create staff ─────────────────────────────────────────────────────────
    public function create(int $companyId, CreateStaffDTO $dto): User
    {
        // Validate role
        $role = $this->staffRepository->findRole($dto->roleId);

        if (!$role) {
            throw StaffException::roleNotFound();
        }

        if (in_array($role->name, self::PROTECTED_ROLES)) {
            throw StaffException::protectedRole($role->name);
        }

        return $this->staffRepository->create($companyId, $dto);
    }

    // ─── Update staff ─────────────────────────────────────────────────────────
    public function update(int $staffId, int $companyId, UpdateStaffDTO $dto): User
    {
        $user = $this->staffRepository->findById($staffId, $companyId);

        if (!$user) {
            throw StaffException::notFound();
        }

        // Validate new role if being changed
        if ($dto->roleId) {
            $role = $this->staffRepository->findRole($dto->roleId);

            if (!$role) {
                throw StaffException::roleNotFound();
            }

            if (in_array($role->name, self::PROTECTED_ROLES)) {
                throw StaffException::protectedRole($role->name);
            }
        }

        return $this->staffRepository->update($user, $dto);
    }

    // ─── Toggle active/inactive ───────────────────────────────────────────────
    public function toggleActive(int $staffId, int $companyId): User
    {
        $user = $this->staffRepository->findById($staffId, $companyId);

        if (!$user) {
            throw StaffException::notFound();
        }

        // Cannot deactivate yourself
        if ($user->id === auth()->id()) {
            throw StaffException::cannotDeactivateSelf();
        }

        return $this->staffRepository->toggleActive($user);
    }

    // ─── Reset password ───────────────────────────────────────────────────────
    public function resetPassword(int $staffId, int $companyId, ResetPasswordDTO $dto): void
    {
        $user = $this->staffRepository->findById($staffId, $companyId);

        if (!$user) {
            throw StaffException::notFound();
        }

        $this->staffRepository->resetPassword($user, $dto);
    }

    // ─── Delete staff ─────────────────────────────────────────────────────────
    public function delete(int $staffId, int $companyId): void
    {
        $user = $this->staffRepository->findById($staffId, $companyId);

        if (!$user) {
            throw StaffException::notFound();
        }

        // Cannot delete yourself
        if ($user->id === auth()->id()) {
            throw StaffException::cannotDeleteSelf();
        }

        // Unassign their leads before deleting
        $this->staffRepository->unassignLeads($user->id);
        $this->staffRepository->delete($user);
    }

    // ─── Performance report ───────────────────────────────────────────────────
    public function performance(int $companyId): Collection
    {
        return $this->staffRepository->getPerformance($companyId);
    }

    // ─── Distinct departments ─────────────────────────────────────────────────
    public function departments(int $companyId): Collection
    {
        return $this->staffRepository->getDepartments($companyId);
    }

    // ─── Available roles ──────────────────────────────────────────────────────
    public function roles(): Collection
    {
        return $this->staffRepository->getRoles();
    }
}
