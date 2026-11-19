<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Tasks became its own plan feature (it used to ride on CRM): every plan that lists CRM keeps Tasks so nobody loses it.
return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('plans')->whereNotNull('modules')->get(['id', 'modules']) as $p) {
            $m = json_decode($p->modules, true);
            if (is_array($m) && in_array('crm', $m, true) && !in_array('tasks', $m, true)) {
                $m[] = 'tasks';
                DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
            }
        }
    }

    public function down(): void {}
};
