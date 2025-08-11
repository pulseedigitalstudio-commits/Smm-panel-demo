<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Order extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'service_id',
        'provider_id',
        'order_number',
        'link',
        'quantity',
        'price',
        'total_price',
        'status',
        'start_count',
        'remains',
        'provider_order_id',
        'provider_response',
        'start_time',
        'finish_time',
        'drip_feed',
        'drip_feed_runs',
        'drip_feed_interval',
        'drip_feed_total_quantity',
        'drip_feed_processed',
        'refill',
        'refill_count',
        'cancel',
        'notes',
        'api_order',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:4',
        'total_price' => 'decimal:4',
        'start_count' => 'integer',
        'remains' => 'integer',
        'start_time' => 'datetime',
        'finish_time' => 'datetime',
        'drip_feed' => 'boolean',
        'drip_feed_runs' => 'integer',
        'drip_feed_interval' => 'integer',
        'drip_feed_total_quantity' => 'integer',
        'drip_feed_processed' => 'integer',
        'refill' => 'boolean',
        'refill_count' => 'integer',
        'cancel' => 'boolean',
        'api_order' => 'boolean',
        'provider_response' => 'array'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'start_count', 'remains', 'finish_time'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function dripFeedOrders()
    {
        return $this->hasMany(DripFeedOrder::class);
    }

    public function refillOrders()
    {
        return $this->hasMany(RefillOrder::class);
    }

    public function ticket()
    {
        return $this->hasOne(Ticket::class);
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

    public function scopeByService($query, $serviceId)
    {
        return $query->where('service_id', $serviceId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopePartial($query)
    {
        return $query->where('status', 'partial');
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }

    public function scopeDripFeed($query)
    {
        return $query->where('drip_feed', true);
    }

    public function scopeRefill($query)
    {
        return $query->where('refill', true);
    }

    // Methods
    public function getFormattedPrice()
    {
        return '$' . number_format($this->price, 4);
    }

    public function getFormattedTotalPrice()
    {
        return '$' . number_format($this->total_price, 4);
    }

    public function getProgressPercentage()
    {
        if ($this->quantity == 0) return 0;
        return round((($this->quantity - $this->remains) / $this->quantity) * 100);
    }

    public function getDeliveredCount()
    {
        return $this->quantity - $this->remains;
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isProcessing()
    {
        return $this->status === 'processing';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    public function isPartial()
    {
        return $this->status === 'partial';
    }

    public function isRefunded()
    {
        return $this->status === 'refunded';
    }

    public function canRefill()
    {
        return $this->refill && $this->isCompleted() && $this->refill_count < 3;
    }

    public function canCancel()
    {
        return in_array($this->status, ['pending', 'processing']) && $this->cancel;
    }

    public function getStatusBadgeClass()
    {
        return match($this->status) {
            'pending' => 'badge-warning',
            'processing' => 'badge-info',
            'completed' => 'badge-success',
            'partial' => 'badge-warning',
            'cancelled' => 'badge-danger',
            'refunded' => 'badge-secondary',
            default => 'badge-secondary'
        };
    }

    public function getDeliveryTime()
    {
        if (!$this->start_time || !$this->finish_time) {
            return null;
        }
        
        return $this->start_time->diffForHumans($this->finish_time, true);
    }

    public function getRemainingTime()
    {
        if (!$this->start_time || $this->isCompleted()) {
            return null;
        }
        
        $estimatedTime = $this->start_time->addMinutes($this->service->start_time ?? 60);
        return now()->diffForHumans($estimatedTime, true);
    }
}