<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Platform-level leads from the public "Book a demo" form (/register). Not tenant-scoped: only superadmins see them.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('demo_requests', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('company_name', 150);
            $t->string('email', 150)->index();
            $t->string('phone', 30);
            $t->string('business_type', 40)->nullable();
            $t->string('team_size', 20)->nullable();
            $t->date('preferred_date')->nullable();
            $t->string('preferred_slot', 20)->nullable();   // morning | afternoon | evening
            $t->text('message')->nullable();
            $t->string('status', 20)->default('new')->index(); // new|contacted|demo_scheduled|demo_done|won|lost
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('scheduled_at')->nullable();
            $t->dateTime('last_contacted_at')->nullable();
            $t->text('notes')->nullable();
            $t->string('source', 100)->nullable();            // utm_source / referrer
            $t->string('ip', 45)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('demo_requests'); }
};
