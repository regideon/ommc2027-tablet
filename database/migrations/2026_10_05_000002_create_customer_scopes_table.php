<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which pulled customers each local user may see, as reported by the
     * portal's customer scope on that user's last completed customer pull.
     * Kept apart from customer_user, which is the editable access list that
     * is pushed back to the portal.
     */
    public function up(): void
    {
        Schema::create('customer_scopes', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->primary(['user_id', 'customer_id']);
        });

        // Customer pull checkpoints are now kept per user; drop the device-wide
        // ones so every user starts with a full pull.
        DB::table('sync_states')->whereIn('key', ['customers.pull_run', 'customers.pulled_through'])->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_scopes');
    }
};
