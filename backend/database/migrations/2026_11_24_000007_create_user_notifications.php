<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each person's own notification inbox (the bell): one row per activity per user, with its own read state. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->string('category', 20)->default('other');   // lead | team | hr | campaign | billing | system | other
            $t->string('type', 40);
            $t->string('title', 200);
            $t->text('body')->nullable();
            $t->json('data')->nullable();                    // action + ids the bell uses to open the right screen
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at', 'id']);
            $t->index(['user_id', 'category', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
