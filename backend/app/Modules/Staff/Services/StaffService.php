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
        $role = $this->staffRepository->findRole($dto->roleId, $companyId);

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
            $role = $this->staffRepository->findRole($dto->roleId, $companyId);

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
    /**
     * Remove a staff member. $plan says what happens to what they hold, each part either handed to another person or released:
     *   leads        {action: transfer|unassign, to_user_id}   (default unassign — the old behaviour)
     *   installments {action: transfer|unassign|none, to_user_id}   their installment follow-ups; "unassign" = back to the lead's owner, "none" = no one at all (only HR can follow them up)
     *   incentives   {action: transfer|cancel|keep, to_user_id}  unpaid incentives only — paid ones stay as history; "cancel" = nobody gets them
     */
    public function delete(int $staffId, int $companyId, array $plan = []): void
    {
        $user = $this->staffRepository->findById($staffId, $companyId);

        if (!$user) {
            throw StaffException::notFound();
        }

        // Cannot delete yourself
        if ($user->id === auth()->id()) {
            throw StaffException::cannotDeleteSelf();
        }

        $target = function (string $key) use ($plan, $companyId, $user): ?int {
            $p = $plan[$key] ?? [];
            if (($p['action'] ?? null) !== 'transfer') { return null; }
            $to = (int) ($p['to_user_id'] ?? 0);
            $ok = $to && $to !== $user->id && \App\Models\User::where('company_id', $companyId)->where('is_active', true)->whereKey($to)->exists();
            if (!$ok) { throw \Illuminate\Validation\ValidationException::withMessages([$key => "Choose an active staff member to hand the {$key} to."]); }
            return $to;
        };
        $leadsTo = $target('leads');
        $instTo = $target('installments');
        $instNobody = (($plan['installments']['action'] ?? null) === 'none');   // no one at all — only HR can follow them up
        $incTo = $target('incentives');
        $incAction = $plan['incentives']['action'] ?? 'keep';

        \Illuminate\Support\Facades\DB::transaction(function () use ($user, $companyId, $leadsTo, $instTo, $instNobody, $incTo, $incAction) {
            // installments first: they reference the leads
            \Illuminate\Support\Facades\DB::table('lead_installments')->where('company_id', $companyId)->where('assigned_to', $user->id)
                ->update(['assigned_to' => $instTo, 'no_followup' => $instNobody, 'updated_at' => now()]);

            if ($leadsTo) {
                $ids = \Illuminate\Support\Facades\DB::table('leads')->where('company_id', $companyId)->where('assigned_to', $user->id)->whereNull('deleted_at')->pluck('id');
                \Illuminate\Support\Facades\DB::table('leads')->whereIn('id', $ids)->update(['assigned_to' => $leadsTo, 'assigned_by' => auth()->id(), 'assigned_at' => now(), 'updated_at' => now()]);
                foreach ($ids->chunk(500) as $chunk) {
                    \Illuminate\Support\Facades\DB::table('lead_events')->insert($chunk->map(fn ($lid) => ['lead_id' => $lid, 'company_id' => $companyId, 'user_id' => auth()->id(),
                        'event' => 'assigned', 'payload' => json_encode(['from' => $user->id, 'to' => $leadsTo, 'reason' => 'staff member removed']), 'created_at' => now(), 'updated_at' => now()])->all());
                }
            } else {
                $this->staffRepository->unassignLeads($user->id);
            }

            $unpaid = \App\Modules\Hr\Models\HrIncentive::where('company_id', $companyId)->where('user_id', $user->id)->whereIn('status', ['pending', 'approved'])->whereNull('payroll_item_id');
            if ($incAction === 'transfer' && $incTo) { (clone $unpaid)->update(['user_id' => $incTo]); }
            elseif ($incAction === 'cancel') { (clone $unpaid)->delete(); }

            $this->staffRepository->delete($user);
        });
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
    public function roles(?int $companyId = null): Collection
    {
        return $this->staffRepository->getRoles($companyId);
    }
}
