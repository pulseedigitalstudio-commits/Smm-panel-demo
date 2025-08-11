<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Ticket extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'user_id',
        'order_id',
        'ticket_number',
        'subject',
        'message',
        'status',
        'priority',
        'department',
        'assigned_to',
        'last_reply_at',
        'last_reply_by',
        'closed_at',
        'closed_by',
        'ip_address',
        'user_agent',
        'attachments',
        'tags',
        'notes'
    ];

    protected $casts = [
        'last_reply_at' => 'datetime',
        'closed_at' => 'datetime',
        'attachments' => 'array',
        'tags' => 'array'
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'priority', 'assigned_to', 'closed_at'])
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

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function lastReplyBy()
    {
        return $this->belongsTo(User::class, 'last_reply_by');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function replies()
    {
        return $this->hasMany(TicketReply::class);
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class);
    }

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByDepartment($query, $department)
    {
        return $query->where('department', $department);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'answered', 'customer_reply']);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_to');
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', 'high');
    }

    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // Methods
    public function isOpen()
    {
        return in_array($this->status, ['open', 'answered', 'customer_reply']);
    }

    public function isClosed()
    {
        return $this->status === 'closed';
    }

    public function isAnswered()
    {
        return $this->status === 'answered';
    }

    public function isCustomerReply()
    {
        return $this->status === 'customer_reply';
    }

    public function isAssigned()
    {
        return !is_null($this->assigned_to);
    }

    public function isHighPriority()
    {
        return in_array($this->priority, ['high', 'urgent']);
    }

    public function isUrgent()
    {
        return $this->priority === 'urgent';
    }

    public function getStatusBadgeClass()
    {
        return match($this->status) {
            'open' => 'badge-danger',
            'answered' => 'badge-warning',
            'customer_reply' => 'badge-info',
            'closed' => 'badge-secondary',
            default => 'badge-secondary'
        };
    }

    public function getPriorityBadgeClass()
    {
        return match($this->priority) {
            'low' => 'badge-success',
            'medium' => 'badge-warning',
            'high' => 'badge-danger',
            'urgent' => 'badge-dark',
            default => 'badge-secondary'
        };
    }

    public function getDepartmentBadgeClass()
    {
        return match($this->department) {
            'general' => 'badge-primary',
            'technical' => 'badge-info',
            'billing' => 'badge-success',
            'sales' => 'badge-warning',
            'support' => 'badge-secondary',
            default => 'badge-secondary'
        };
    }

    public function getStatusText()
    {
        return ucfirst(str_replace('_', ' ', $this->status));
    }

    public function getPriorityText()
    {
        return ucfirst($this->priority);
    }

    public function getDepartmentText()
    {
        return ucfirst($this->department);
    }

    public function getLastReplyTime()
    {
        if (!$this->last_reply_at) {
            return 'No replies yet';
        }
        
        return $this->last_reply_at->diffForHumans();
    }

    public function getClosedTime()
    {
        if (!$this->closed_at) {
            return null;
        }
        
        return $this->closed_at->diffForHumans();
    }

    public function getRepliesCount()
    {
        return $this->replies()->count();
    }

    public function getAttachmentsCount()
    {
        return $this->attachments()->count();
    }

    public function markAsAnswered($userId)
    {
        $this->update([
            'status' => 'answered',
            'last_reply_at' => now(),
            'last_reply_by' => $userId
        ]);
    }

    public function markAsCustomerReply()
    {
        $this->update(['status' => 'customer_reply']);
    }

    public function close($userId)
    {
        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $userId
        ]);
    }

    public function reopen()
    {
        $this->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null
        ]);
    }

    public function assignTo($userId)
    {
        $this->update(['assigned_to' => $userId]);
    }

    public function unassign()
    {
        $this->update(['assigned_to' => null]);
    }

    public function updatePriority($priority)
    {
        $this->update(['priority' => $priority]);
    }

    public function addTag($tag)
    {
        $tags = $this->tags ?: [];
        if (!in_array($tag, $tags)) {
            $tags[] = $tag;
            $this->update(['tags' => $tags]);
        }
    }

    public function removeTag($tag)
    {
        $tags = $this->tags ?: [];
        $tags = array_filter($tags, fn($t) => $t !== $tag);
        $this->update(['tags' => array_values($tags)]);
    }

    public function hasTag($tag)
    {
        return in_array($tag, $this->tags ?: []);
    }

    public function getTagsList()
    {
        return $this->tags ?: [];
    }

    public function getFormattedTags()
    {
        if (!$this->tags) {
            return 'No tags';
        }
        
        return implode(', ', $this->tags);
    }

    public function incrementReplyCount()
    {
        $this->increment('replies_count');
    }

    public function getTimeSinceLastReply()
    {
        if (!$this->last_reply_at) {
            return 'Never';
        }
        
        return $this->last_reply_at->diffForHumans();
    }

    public function getTimeSinceCreated()
    {
        return $this->created_at->diffForHumans();
    }

    public function getTimeSinceClosed()
    {
        if (!$this->closed_at) {
            return null;
        }
        
        return $this->closed_at->diffForHumans();
    }
}