<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The original AI-fields migration stored a non-resolvable model id
 * ("claude-haiku-4-5") as the default. Anthropic needs a full dated id.
 */
return new class extends Migration {
    private string $old = 'claude-haiku-4-5';
    private string $new = 'claude-haiku-4-5-20251001';

    public function up(): void
    {
        if (Schema::hasColumn('companies', 'ai_model')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('ai_model', 100)->default($this->new)->change();
            });

            DB::table('companies')->where('ai_model', $this->old)->update(['ai_model' => $this->new]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'ai_model')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('ai_model', 100)->default($this->old)->change();
            });
        }
    }
};
