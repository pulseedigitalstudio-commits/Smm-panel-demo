<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, LogsActivity;

    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'phone',
        'country',
        'balance',
        'status',
        'email_verified_at',
        'api_token',
        'is_reseller',
        'reseller_percentage',
        'parent_id',
        'avatar',
        'timezone',
        'language',
        'currency',
        'referral_code',
        'referred_by',
        'last_login_at',
        'last_login_ip'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'api_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'balance' => 'decimal:2',
        'is_reseller' => 'boolean',
        'reseller_percentage' => 'decimal:2',
        'status' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'balance', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function resellerClients()
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    public function parentReseller()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeResellers($query)
    {
        return $query->where('is_reseller', true);
    }

    // Methods
    public function hasBalance($amount)
    {
        return $this->balance >= $amount;
    }

    public function deductBalance($amount)
    {
        $this->decrement('balance', $amount);
    }

    public function addBalance($amount)
    {
        $this->increment('balance', $amount);
    }

    public function isReseller()
    {
        return $this->is_reseller;
    }

    public function getResellerPrice($price)
    {
        if (!$this->is_reseller) {
            return $price;
        }
        
        $discount = ($this->reseller_percentage / 100) * $price;
        return $price - $discount;
    }
}