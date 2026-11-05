<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaPayment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'customer_unique_id',
        'purpose',
        'phone',
        'amount',
        'currency',
        'account_reference',
        'merchant_request_id',
        'checkout_request_id',
        'mpesa_receipt',
        'status',
        'result_code',
        'result_desc',
        'raw_response',
        'raw_callback',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_response' => 'array',
        'raw_callback' => 'array',
        'paid_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(CustomersInfo::class, 'customer_unique_id', 'customer_unique_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
