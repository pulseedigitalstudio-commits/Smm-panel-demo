<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Service extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'category_id',
        'provider_id',
        'price',
        'reseller_price',
        'min_quantity',
        'max_quantity',
        'drip_feed',
        'refill',
        'cancel',
        'status',
        'api_service_id',
        'type',
        'posts',
        'link',
        'sample_link',
        'features',
        'start_time',
        'speed',
        'quality',
        'sort_order'
    ];

    protected $casts = [
        'price' => 'decimal:4',
        'reseller_price' => 'decimal:4',
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'drip_feed' => 'boolean',
        'refill' => 'boolean',
        'cancel' => 'boolean',
        'status' => 'boolean',
        'posts' => 'integer',
        'start_time' => 'integer',
        'speed' => 'integer',
        'quality' => 'integer',
        'sort_order' => 'integer',
        'features' => 'array'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'price', 'status', 'category_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function prices()
    {
        return $this->hasMany(ServicePrice::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByProvider($query, $providerId)
    {
        return $query->where('provider_id', $providerId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Methods
    public function getFormattedPrice()
    {
        return '$' . number_format($this->price, 4);
    }

    public function getFormattedResellerPrice()
    {
        return '$' . number_format($this->reseller_price, 4);
    }

    public function isAvailable()
    {
        return $this->status && $this->provider && $this->provider->status;
    }

    public function canDripFeed()
    {
        return $this->drip_feed;
    }

    public function canRefill()
    {
        return $this->refill;
    }

    public function canCancel()
    {
        return $this->cancel;
    }

    public function getDeliveryTime()
    {
        if ($this->start_time <= 60) {
            return $this->start_time . ' minutes';
        } elseif ($this->start_time <= 1440) {
            return round($this->start_time / 60, 1) . ' hours';
        } else {
            return round($this->start_time / 1440, 1) . ' days';
        }
    }

    public function getSpeedText()
    {
        if ($this->speed <= 100) {
            return 'Very Slow';
        } elseif ($this->speed <= 1000) {
            return 'Slow';
        } elseif ($this->speed <= 5000) {
            return 'Normal';
        } elseif ($this->speed <= 10000) {
            return 'Fast';
        } else {
            return 'Very Fast';
        }
    }

    public function getQualityText()
    {
        if ($this->quality <= 50) {
            return 'Low';
        } elseif ($this->quality <= 80) {
            return 'Medium';
        } else {
            return 'High';
        }
    }
}