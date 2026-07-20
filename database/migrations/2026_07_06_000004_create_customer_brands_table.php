<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('material_group_id')->constrained();
            $table->foreignId('brand_id')->constrained();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('brand_other')->nullable();
            $table->unsignedBigInteger('last_salescall_id')->nullable();
            $table->unsignedBigInteger('last_updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_brands');
    }
};
