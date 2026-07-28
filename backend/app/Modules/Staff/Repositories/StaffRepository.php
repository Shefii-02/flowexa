<?php

namespace App\Modules\Staff\Repositories;

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Modules\Staff\DTOs\CreateStaffDTO;
use App\Modules\Staff\DTOs\ResetPasswordDTO;
use App\Modules\Staff\DTOs\StaffFilterDTO;
use App\Modules\Staff\DTOs\StaffPerformanceDTO;
use App\Modules\Staff\DTOs\UpdateStaffDTO;
use App\Modules\Staff\Repositories\Interfaces\StaffRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class StaffRepository implements StaffRepositoryInterface
{
    // ─── Paginate staff list ──────────────────────────────────────────────────
    public function paginate(int $companyId, StaffFilterDTO $filter): LengthAwarePaginator
    {
        return User::with('role')
            ->where('company_id', $companyId)
            ->withCount([
                'leads as total_leads',
                'leads as active_leads' => fn($q) => $q->whereNotIn('stage', ['enrolled', 'lost']),
            ])
            ->when($filter->search, fn($q) =>
                $q->where(fn($inner) =>
                    $inner->where('name',  'like', "%{$filter->search}%")
                          ->orWhere('email','like', "%{$filter->search}%")
                          ->orWhere('phone','like', "%{$filter->search}%")
                )
            )
            ->when($filter->role, fn($q) =>
                $q->whereHas('role', fn($r) => $r->where('name', $filter->role))
            )
            ->when($filter->department, fn($q) =>
                $q->where('department', $filter->department)
            )
            ->when(!is_null($filter->isActive), fn($q) =>
                $q->where('is_active', $filter->isActive)
            )
            ->latest()
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    // ─── Find by ID scoped to company ────────────────────────────────────────
    public function findById(int $id, int $companyId): ?User
    {
        return User::with(['role'])
            ->withCount([
                'leads as total_leads',
                'leads as active_leads'    => fn($q) => $q->whereNotIn('stage', ['enrolled','lost']),
                'leads as enrolled_leads'  => fn($q) => $q->where('stage','enrolled'),
            ])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();
    }

    // ─── Create staff member ──────────────────────────────────────────────────
    public function create(int $companyId, CreateStaffDTO $dto): User
    {
        $user = User::create([
            'company_id' => $companyId,
            'role_id'    => $dto->roleId,
            'name'       => $dto->name,
            'email'      => $dto->email,
            'password'   => Hash::make($dto->password),
            'phone'      => $dto->phone,
            'department' => $dto->department,
            'max_leads'  => $dto->maxLeads,
            'is_active'  => true,
        ]);

        return $user->load('role');
    }

    // ─── Update staff member ──────────────────────────────────────────────────
    public function update(User $user, UpdateStaffDTO $dto): User
    {
        $data = array_filter([
            'name'       => $dto->name,
            'role_id'    => $dto->roleId,
            'phone'      => $dto->phone,
            'department' => $dto->department,
            'max_leads'  => $dto->maxLeads,
            'is_active'  => $dto->isActive,
        ], fn($v) => !is_null($v));

        $user->update($data);
        return $user->fresh('role');
    }

    // ─── Toggle active status ─────────────────────────────────────────────────
    public function toggleActive(User $user): User
    {
        $user->update(['is_active' => !$user->is_active]);
        return $user->fresh('role');
    }

    // ─── Reset password ───────────────────────────────────────────────────────
    public function resetPassword(User $user, ResetPasswordDTO $dto): void
    {
        $user->update(['password' => Hash::make($dto->password)]);
    }

    // ─── Soft delete staff ────────────────────────────────────────────────────
    public function delete(User $user): void
    {
        $user->delete();
    }

    // ─── Performance data for all counsellors/team leads ─────────────────────
    public function getPerformance(int $companyId): Collection
    {
        return User::with('role')
            ->where('company_id', $companyId)
            ->whereHas('role', fn($q) =>
                $q->whereIn('name', ['counsellor', 'team_lead', 'admin'])
            )
            ->withCount([
                'leads as total_leads',
                'leads as new_leads'        => fn($q) => $q->where('stage', 'new'),
                'leads as contacted_leads'  => fn($q) => $q->where('stage', 'contacted'),
                'leads as follow_up_leads'  => fn($q) => $q->where('stage', 'follow_up'),
                'leads as enrolled_leads'   => fn($q) => $q->where('stage', 'enrolled'),
                'leads as lost_leads'       => fn($q) => $q->where('stage', 'lost'),
                'leads as active_leads'     => fn($q) => $q->whereNotIn('stage', ['enrolled','lost']),
            ])
            ->get()
            ->map(fn(User $u) => new StaffPerformanceDTO(
                userId:          $u->id,
                name:            $u->name,
                email:           $u->email,
                department:      $u->department,
                role:            $u->role?->label,
                totalLeads:      $u->total_leads,
                newLeads:        $u->new_leads,
                contactedLeads:  $u->contacted_leads,
                followUpLeads:   $u->follow_up_leads,
                enrolledLeads:   $u->enrolled_leads,
                lostLeads:       $u->lost_leads,
                conversionRate:  $u->total_leads
                    ? round(($u->enrolled_leads / $u->total_leads) * 100, 1)
                    : 0.0,
                activeLeads:     $u->active_leads,
                maxLeads:        $u->max_leads,
                capacityPercent: $u->max_leads
                    ? round(($u->active_leads / $u->max_leads) * 100, 1)
                    : 0.0,
            ));
    }

    // ─── Distinct departments in this company ─────────────────────────────────
    public function getDepartments(int $companyId): Collection
    {
        return User::where('company_id', $companyId)
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');
    }

    // ─── All assignable roles (exclude superadmin + owner) ────────────────────
    public function getRoles(): Collection
    {
        return Role::whereNotIn('name', ['superadmin', 'owner'])
            ->orderBy('id')
            ->get();
    }

    // ─── Find a role by ID ────────────────────────────────────────────────────
    public function findRole(int $roleId): ?Role
    {
        return Role::find($roleId);
    }

    // ─── Count active leads for a user ────────────────────────────────────────
    public function countActiveLeads(int $userId): int
    {
        return Lead::where('assigned_to', $userId)
            ->whereNotIn('stage', ['enrolled', 'lost'])
            ->count();
    }

    // ─── Unassign all leads from a user ──────────────────────────────────────
    public function unassignLeads(int $userId): void
    {
        Lead::where('assigned_to', $userId)
            ->update([
                'assigned_to' => null,
                'assigned_by' => null,
            ]);
    }
}
