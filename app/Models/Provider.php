<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Provider extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'api_url',
        'api_key',
        'api_secret',
        'status',
        'balance',
        'currency',
        'min_order',
        'max_order',
        'drip_feed',
        'refill',
        'cancel',
        'test_mode',
        'timeout',
        'retry_attempts',
        'last_check',
        'last_error',
        'error_count',
        'success_rate',
        'total_orders',
        'successful_orders',
        'failed_orders',
        'average_response_time',
        'notes',
        'logo',
        'website',
        'contact_email',
        'contact_phone',
        'support_hours',
        'timezone'
    ];

    protected $hidden = [
        'api_key',
        'api_secret'
    ];

    protected $casts = [
        'status' => 'boolean',
        'balance' => 'decimal:2',
        'min_order' => 'integer',
        'max_order' => 'integer',
        'drip_feed' => 'boolean',
        'refill' => 'boolean',
        'cancel' => 'boolean',
        'test_mode' => 'boolean',
        'timeout' => 'integer',
        'retry_attempts' => 'integer',
        'last_check' => 'datetime',
        'error_count' => 'integer',
        'success_rate' => 'decimal:2',
        'total_orders' => 'integer',
        'successful_orders' => 'integer',
        'failed_orders' => 'integer',
        'average_response_time' => 'integer'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'balance', 'last_check'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function apiLogs()
    {
        return $this->hasMany(ApiLog::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeTestMode($query)
    {
        return $query->where('test_mode', true);
    }

    public function scopeLiveMode($query)
    {
        return $query->where('test_mode', false);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOrderBySuccessRate($query)
    {
        return $query->orderBy('success_rate', 'desc');
    }

    public function scopeOrderByResponseTime($query)
    {
        return $query->orderBy('average_response_time', 'asc');
    }

    // Methods
    public function isActive()
    {
        return $this->status;
    }

    public function isTestMode()
    {
        return $this->test_mode;
    }

    public function hasBalance()
    {
        return $this->balance > 0;
    }

    public function canHandleOrder($quantity)
    {
        return $quantity >= $this->min_order && $quantity <= $this->max_order;
    }

    public function supportsDripFeed()
    {
        return $this->drip_feed;
    }

    public function supportsRefill()
    {
        return $this->refill;
    }

    public function supportsCancel()
    {
        return $this->cancel;
    }

    public function getFormattedBalance()
    {
        return $this->currency . ' ' . number_format($this->balance, 2);
    }

    public function getSuccessRatePercentage()
    {
        return round($this->success_rate, 2) . '%';
    }

    public function getAverageResponseTimeText()
    {
        if ($this->average_response_time <= 60) {
            return $this->average_response_time . ' seconds';
        } elseif ($this->average_response_time <= 3600) {
            return round($this->average_response_time / 60, 1) . ' minutes';
        } else {
            return round($this->average_response_time / 3600, 1) . ' hours';
        }
    }

    public function getLastCheckText()
    {
        if (!$this->last_check) {
            return 'Never';
        }
        return $this->last_check->diffForHumans();
    }

    public function incrementErrorCount()
    {
        $this->increment('error_count');
    }

    public function resetErrorCount()
    {
        $this->update(['error_count' => 0]);
    }

    public function updateSuccessRate()
    {
        if ($this->total_orders > 0) {
            $this->success_rate = ($this->successful_orders / $this->total_orders) * 100;
            $this->save();
        }
    }

    public function incrementOrderCount($success = true)
    {
        $this->increment('total_orders');
        
        if ($success) {
            $this->increment('successful_orders');
        } else {
            $this->increment('failed_orders');
        }
        
        $this->updateSuccessRate();
    }

    public function updateResponseTime($responseTime)
    {
        if ($this->total_orders > 0) {
            $currentTotal = $this->average_response_time * ($this->total_orders - 1);
            $this->average_response_time = ($currentTotal + $responseTime) / $this->total_orders;
            $this->save();
        } else {
            $this->average_response_time = $responseTime;
            $this->save();
        }
    }

    public function getApiEndpoint($endpoint = '')
    {
        $baseUrl = rtrim($this->api_url, '/');
        $endpoint = ltrim($endpoint, '/');
        return $baseUrl . '/' . $endpoint;
    }

    public function getApiHeaders()
    {
        return [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'SMM-Panel/1.0'
        ];
    }

    public function logApiCall($endpoint, $request, $response, $statusCode, $responseTime)
    {
        $this->apiLogs()->create([
            'endpoint' => $endpoint,
            'request' => $request,
            'response' => $response,
            'status_code' => $statusCode,
            'response_time' => $responseTime,
            'success' => $statusCode >= 200 && $statusCode < 300
        ]);
    }
}