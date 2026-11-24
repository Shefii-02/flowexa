<?php

use App\Models\Permission;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/** "Sign in as staff" — an owner can always; this lets a role (or one person, via extra permissions) do it too. Not granted to anyone by default. */
return new class extends Migration {
    public function up(): void
    {
        foreach (PermissionsSeeder::PERMISSIONS as $perm) {
            if ($perm['key'] === 'staff.login_as') Permission::updateOrCreate(['key' => $perm['key']], $perm);
        }
    }

    public function down(): void
    {
        Permission::where('key', 'staff.login_as')->delete();
    }
};
