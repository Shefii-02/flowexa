<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Instagram CDN profile-picture URLs carry long signed query strings (>600 chars).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE instagram_accounts MODIFY profile_picture_url TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE instagram_accounts MODIFY profile_picture_url VARCHAR(600) NULL');
    }
};
