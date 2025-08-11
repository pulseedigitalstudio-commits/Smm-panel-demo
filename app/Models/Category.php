<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Category extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'color',
        'status',
        'sort_order',
        'parent_id',
        'meta_title',
        'meta_description',
        'image'
    ];

    protected $casts = [
        'status' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'status', 'sort_order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function scopeParent($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeChild($query)
    {
        return $query->whereNotNull('parent_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeByParent($query, $parentId)
    {
        return $query->where('parent_id', $parentId);
    }

    // Methods
    public function isParent()
    {
        return is_null($this->parent_id);
    }

    public function isChild()
    {
        return !is_null($this->parent_id);
    }

    public function hasChildren()
    {
        return $this->children()->count() > 0;
    }

    public function hasServices()
    {
        return $this->services()->count() > 0;
    }

    public function getActiveServicesCount()
    {
        return $this->services()->active()->count();
    }

    public function getServicesCount()
    {
        return $this->services()->count();
    }

    public function getFullName()
    {
        if ($this->isChild() && $this->parent) {
            return $this->parent->name . ' > ' . $this->name;
        }
        return $this->name;
    }

    public function getBreadcrumb()
    {
        $breadcrumb = [$this->name];
        
        if ($this->isChild() && $this->parent) {
            $breadcrumb = array_merge($this->parent->getBreadcrumb(), $breadcrumb);
        }
        
        return $breadcrumb;
    }

    public function getIconClass()
    {
        return $this->icon ?: 'fas fa-folder';
    }

    public function getColorClass()
    {
        return $this->color ?: 'text-primary';
    }
}