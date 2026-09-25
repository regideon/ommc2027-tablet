<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('rsm_id')->nullable()->index()->after('email');
        });

        Schema::create('customer_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['rsm_id']);
            $table->dropColumn('rsm_id');
        });
    }
};
