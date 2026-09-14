<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A record of every email a company sent through their connected mailbox — alerts, notifications, announcements. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject');
            // 'alert' (order/booking confirmations etc.), 'notification' (system events),
            // 'announcement' (broadcast to many contacts at once).
            $table->string('kind')->default('notification');
            $table->string('status')->default('sent'); // sent | failed
            $table->text('error')->nullable();

            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
