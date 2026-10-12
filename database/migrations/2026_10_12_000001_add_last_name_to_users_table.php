<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'last_name')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('last_name', 191)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'last_name')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_name');
        });
    }
};
