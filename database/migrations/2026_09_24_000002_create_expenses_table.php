<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('local_uuid', 36)->unique();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->foreignId('salescall_id')->constrained('salescalls')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('expense_type_id')->constrained('expense_types');
            $table->foreignId('created_by')->constrained('users');

            $table->decimal('amount', 12, 4)->nullable();
            $table->date('date_filed')->nullable();
            $table->string('payment_type')->nullable();
            $table->text('payment_remarks')->nullable();
            $table->string('invoice_number')->nullable();
            $table->boolean('with_invoice')->nullable();
            $table->string('establishment')->nullable();
            $table->text('location')->nullable();
            $table->text('purpose')->nullable();
            $table->string('tin')->nullable();
            $table->decimal('latitude', 10, 6)->nullable();
            $table->decimal('longitude', 10, 6)->nullable();

            $table->json('form_data')->nullable();
            $table->unsignedSmallInteger('form_schema_version')->default(1);

            $table->boolean('approved')->nullable();
            $table->text('approver_remarks')->nullable();

            $table->string('sync_status', 20)->default('pending');
            $table->unsignedTinyInteger('sync_attempts')->default(0);
            $table->text('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('server_id');
            $table->index('salescall_id');
            $table->index('customer_id');
            $table->index('expense_type_id');
            $table->index('created_by');
            $table->index('date_filed');
            $table->index('approved');
            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        // Expense schema is forward-only by project safety policy.
    }
};
