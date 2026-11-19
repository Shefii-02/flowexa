<?php

use App\Models\Plan;
use Illuminate\Database\Migrations\Migration;

/**
 * A plan with an explicit permission list (Plan → Included features → permissions) caps what its roles can use;
 * NULL means "everything". The permissions added alongside lead verification, lead recall and sales-activity monitoring
 * would be silently blocked on every such plan — even for roles that hold them — so open them on the plans that already
 * include the feature they belong to:
 *
 *   leads.verify, leads.recall           plans that include leads.view or leads.manage
 *   hr.sales_activity.view / .manage     plans that include any hr.* permission
 */
return new class extends Migration {
    public function up(): void
    {
        Plan::query()->whereNotNull('permissions')->get()->each(function (Plan $plan) {
            $have = $plan->permissions ?? [];
            $add = [];
            if (array_intersect(['leads.view', 'leads.manage'], $have)) {
                array_push($add, 'leads.verify', 'leads.recall');
            }
            if (array_filter($have, fn ($k) => str_starts_with((string) $k, 'hr.'))) {
                array_push($add, 'hr.sales_activity.view', 'hr.sales_activity.manage');
            }
            $merged = array_values(array_unique(array_merge($have, $add)));
            if ($merged !== array_values($have)) {
                $plan->forceFill(['permissions' => $merged])->save();
            }
        });
    }

    public function down(): void {}
};
