<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Heads (categories) are created by each person; the AI only picks from them or suggests one.
        Schema::create('finance_heads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('type', 10); // income | expense
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'type', 'name']);
        });

        Schema::create('finance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('head_id')->nullable()->constrained('finance_heads')->nullOnDelete();
            $table->string('type', 10); // income | expense
            $table->decimal('amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->date('entry_date');
            $table->string('party', 180)->nullable();      // vendor (expense) or payer (income)
            $table->string('bill_no', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('payment_method', 24)->nullable();
            $table->string('source', 12)->default('manual'); // manual | scan | assistant
            $table->boolean('needs_review')->default(false);
            $table->string('ai_suggested_head', 80)->nullable();
            $table->unsignedTinyInteger('ai_confidence')->nullable(); // 0-100
            $table->json('extraction')->nullable();
            $table->string('receipt_disk', 32)->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->string('receipt_mime', 120)->nullable();
            $table->unsignedBigInteger('receipt_size')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'entry_date']);
            $table->index(['user_id', 'type', 'entry_date']);
            $table->index(['user_id', 'bill_no']);
        });

        // Other calendars (Outlook, Google, iPhone) imported through their private ICS links.
        Schema::create('calendar_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('url'); // stored encrypted (model cast)
            $table->string('color', 16)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('events_count')->default(0);
            $table->timestamps();
            $table->index(['user_id']);
        });

        Schema::create('external_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('uid_hash', 64);
            $table->string('title', 240);
            $table->string('location', 240)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'starts_at']);
            $table->index(['calendar_source_id', 'uid_hash']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('calendar_feed_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void { $table->dropUnique(['calendar_feed_token']); $table->dropColumn('calendar_feed_token'); });
        Schema::dropIfExists('external_events');
        Schema::dropIfExists('calendar_sources');
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('finance_heads');
    }
};
