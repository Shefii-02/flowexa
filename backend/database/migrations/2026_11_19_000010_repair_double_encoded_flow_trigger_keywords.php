<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Flows imported from a JSON file stored trigger_keywords JSON-encoded twice (a string, not a list). Rewrite them as a proper list. */
return new class extends Migration {
    public function up(): void
    {
        foreach (DB::table('flow_builders')->select('id', 'trigger_keywords')->get() as $row) {
            $v = $row->trigger_keywords;
            $fixed = false;
            for ($i = 0; $i < 3 && is_string($v); $i++) { $v = json_decode($v, true); $fixed = $fixed || is_string($v) || is_array($v); }
            if (!is_array($v)) { $v = []; }
            $new = json_encode(array_values($v), JSON_UNESCAPED_UNICODE);
            if ($new !== $row->trigger_keywords) {
                DB::table('flow_builders')->where('id', $row->id)->update(['trigger_keywords' => $new]);
            }
        }
    }

    public function down(): void {}
};
