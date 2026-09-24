<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:4',
        'date_filed' => 'date',
        'with_invoice' => 'boolean',
        'form_data' => 'array',
        'approved' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function salescall(): BelongsTo
    {
        return $this->belongsTo(Salescall::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class);
    }
}
