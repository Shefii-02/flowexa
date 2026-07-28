<?php

namespace App\Trait;

trait SuperAdminPermissionsTrait
{
    public function permissions(): \Illuminate\Http\JsonResponse
    {
        $roles = \App\Models\Role::all()->map(fn($r) => [
            'id'          => $r->id,
            'name'        => $r->name,
            'label'       => $r->label,
            'is_system'   => $r->is_system,
            'permissions' => $r->permissions,
        ]);

        $allPermissions = [
            'contacts'   => ['contacts.view','contacts.create','contacts.edit','contacts.delete','contacts.import'],
            'labels'     => ['labels.view','labels.manage'],
            'staff'      => ['staff.view','staff.create','staff.edit','staff.delete'],
            'flow'       => ['flow.view','flow.manage'],
            'campaigns'  => ['campaigns.view','campaigns.create','campaigns.edit','campaigns.delete','campaigns.launch'],
            'leads'      => ['leads.view_own','leads.view_all','leads.create','leads.edit','leads.assign','leads.delete'],
            'analytics'  => ['analytics.view_own','analytics.view_all'],
            'billing'    => ['billing.view','billing.manage'],
            'settings'   => ['settings.manage'],
            'crm'        => ['crm.sync'],
        ];

        return response()->json(['roles' => $roles, 'all_permissions' => $allPermissions]);
    }

    public function updatePermissions(\Illuminate\Http\Request $request, int $roleId): \Illuminate\Http\JsonResponse
    {
        $request->validate(['permissions' => ['required','array']]);
        $role = \App\Models\Role::findOrFail($roleId);

        // System roles (superadmin, owner) cannot have permissions reduced
        if (in_array($role->name, ['superadmin','owner'])) {
            return response()->json(['message' => 'Cannot modify superadmin or owner permissions.'], 403);
        }

        $role->update(['permissions' => $request->permissions]);
        return response()->json(['message' => 'Permissions updated.', 'role' => $role->fresh()]);
    }


}
