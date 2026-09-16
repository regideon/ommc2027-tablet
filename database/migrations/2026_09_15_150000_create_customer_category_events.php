<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_category_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('profile_type', 20);
            $table->string('stream', 20);
            $table->string('category');
            $table->timestamp('effective_at');
            $table->string('event_key', 100)->unique();
            $table->string('source', 30);
            $table->unsignedBigInteger('supersedes_event_id')->nullable()->index();
            $table->string('supersedes_event_key', 100)->nullable();
            $table->index(['customer_id', 'profile_type', 'stream', 'effective_at', 'id'], 'customer_category_events_timeline_index');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_category_events');
    }
};
