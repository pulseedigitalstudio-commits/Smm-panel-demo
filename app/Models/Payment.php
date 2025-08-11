<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Payment extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'order_id',
        'payment_method',
        'amount',
        'currency',
        'status',
        'transaction_id',
        'gateway_response',
        'gateway_fee',
        'processing_fee',
        'total_amount',
        'description',
        'metadata',
        'paid_at',
        'expires_at',
        'refunded_at',
        'refund_amount',
        'refund_reason',
        'webhook_data',
        'ip_address',
        'user_agent',
        'notes'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_fee' => 'decimal:2',
        'processing_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'refunded_at' => 'datetime',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'webhook_data' => 'array'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'transaction_id', 'paid_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function gateway()
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_method', 'code');
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByMethod($query, $method)
    {
        return $query->where('payment_method', $method);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', ['completed', 'pending']);
    }

    // Methods
    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isFailed()
    {
        return $this->status === 'failed';
    }

    public function isRefunded()
    {
        return $this->status === 'refunded';
    }

    public function isExpired()
    {
        return $this->status === 'expired';
    }

    public function isSuccessful()
    {
        return in_array($this->status, ['completed', 'pending']);
    }

    public function getFormattedAmount()
    {
        return $this->currency . ' ' . number_format($this->amount, 2);
    }

    public function getFormattedTotalAmount()
    {
        return $this->currency . ' ' . number_format($this->total_amount, 2);
    }

    public function getFormattedGatewayFee()
    {
        return $this->currency . ' ' . number_format($this->gateway_fee, 2);
    }

    public function getFormattedProcessingFee()
    {
        return $this->currency . ' ' . number_format($this->processing_fee, 2);
    }

    public function getFormattedRefundAmount()
    {
        return $this->currency . ' ' . number_format($this->refund_amount, 2);
    }

    public function getStatusBadgeClass()
    {
        return match($this->status) {
            'pending' => 'badge-warning',
            'completed' => 'badge-success',
            'failed' => 'badge-danger',
            'refunded' => 'badge-secondary',
            'expired' => 'badge-dark',
            'cancelled' => 'badge-danger',
            default => 'badge-secondary'
        };
    }

    public function getStatusText()
    {
        return ucfirst($this->status);
    }

    public function getPaymentMethodText()
    {
        return ucfirst(str_replace('_', ' ', $this->payment_method));
    }

    public function getPaymentMethodIcon()
    {
        return match($this->payment_method) {
            'stripe' => 'fab fa-stripe',
            'paypal' => 'fab fa-paypal',
            'razorpay' => 'fas fa-credit-card',
            'coinpayments' => 'fab fa-bitcoin',
            'bank_transfer' => 'fas fa-university',
            'manual' => 'fas fa-hand-holding-usd',
            default => 'fas fa-credit-card'
        };
    }

    public function calculateFees()
    {
        $this->gateway_fee = $this->gateway ? $this->gateway->calculateFee($this->amount) : 0;
        $this->processing_fee = $this->gateway ? $this->gateway->processing_fee : 0;
        $this->total_amount = $this->amount + $this->gateway_fee + $this->processing_fee;
        $this->save();
    }

    public function markAsPaid($transactionId = null, $gatewayResponse = null)
    {
        $this->update([
            'status' => 'completed',
            'transaction_id' => $transactionId,
            'gateway_response' => $gatewayResponse,
            'paid_at' => now()
        ]);

        // Add balance to user
        $this->user->addBalance($this->amount);

        // Create activity log
        activity()
            ->performedOn($this)
            ->log('Payment completed');
    }

    public function markAsFailed($reason = null, $gatewayResponse = null)
    {
        $this->update([
            'status' => 'failed',
            'gateway_response' => $gatewayResponse,
            'notes' => $reason
        ]);
    }

    public function markAsExpired()
    {
        $this->update(['status' => 'expired']);
    }

    public function refund($amount = null, $reason = null)
    {
        $refundAmount = $amount ?: $this->amount;
        
        $this->update([
            'status' => 'refunded',
            'refund_amount' => $refundAmount,
            'refund_reason' => $reason,
            'refunded_at' => now()
        ]);

        // Deduct balance from user
        $this->user->deductBalance($refundAmount);

        // Create activity log
        activity()
            ->performedOn($this)
            ->log('Payment refunded: ' . $reason);
    }

    public function isExpired()
    {
        return $this->expires_at && now()->isAfter($this->expires_at);
    }

    public function getExpiryTime()
    {
        if (!$this->expires_at) {
            return null;
        }
        
        return $this->expires_at->diffForHumans();
    }

    public function getPaidTime()
    {
        if (!$this->paid_at) {
            return null;
        }
        
        return $this->paid_at->diffForHumans();
    }

    public function getRefundedTime()
    {
        if (!$this->refunded_at) {
            return null;
        }
        
        return $this->refunded_at->diffForHumans();
    }
}