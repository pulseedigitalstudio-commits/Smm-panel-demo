<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TicketController extends Controller
{
    /**
     * Display a listing of tickets with search, filter, and sort options
     */
    public function index(Request $request)
    {
        $query = Ticket::with(['user', 'order'])
            ->withCount(['replies']);

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('ticket_id', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('username', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by priority
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by department
        if ($request->filled('department')) {
            $query->where('department', $request->department);
        }

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Filter by last reply
        if ($request->filled('last_reply')) {
            $days = $request->last_reply;
            $query->where('updated_at', '<=', now()->subDays($days));
        }

        // Sort options
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['subject', 'status', 'priority', 'department', 'created_at', 'updated_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $tickets = $query->paginate(20);

        // Get ticket statistics
        $stats = $this->getTicketStats();

        return view('admin.tickets.index', compact('tickets', 'stats'));
    }

    /**
     * Show the form for creating a new ticket
     */
    public function create()
    {
        $users = User::where('status', 'active')->get();
        $orders = Order::where('status', 'completed')->get();

        return view('admin.tickets.create', compact('users', 'orders'));
    }

    /**
     * Store a newly created ticket
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
            'priority' => 'required|in:low,medium,high,urgent',
            'department' => 'required|in:general,technical,billing,sales,support',
            'status' => 'required|in:open,answered,closed,waiting',
            'order_id' => 'nullable|exists:orders,id',
            'internal_notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $ticket = Ticket::create([
                'user_id' => $validated['user_id'],
                'subject' => $validated['subject'],
                'message' => $validated['message'],
                'priority' => $validated['priority'],
                'department' => $validated['department'],
                'status' => $validated['status'],
                'order_id' => $validated['order_id'],
                'internal_notes' => $validated['internal_notes'],
                'ticket_id' => 'TIC-' . uniqid(),
                'admin_id' => auth()->id(),
            ]);

            // Log the creation
            Log::info('Admin created ticket', [
                'admin_id' => auth()->id(),
                'ticket_id' => $ticket->id,
                'user_id' => $ticket->user_id,
                'subject' => $ticket->subject,
            ]);

            DB::commit();

            return redirect()->route('admin.tickets.show', $ticket)
                ->with('success', 'Ticket created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create ticket', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to create ticket. Please try again.');
        }
    }

    /**
     * Display the specified ticket
     */
    public function show(Ticket $ticket)
    {
        $ticket->load(['user', 'order', 'replies.user', 'replies.admin']);

        // Get ticket statistics
        $stats = $this->getTicketStats($ticket->id);

        // Get related tickets
        $relatedTickets = Ticket::where('user_id', $ticket->user_id)
            ->where('id', '!=', $ticket->id)
            ->latest()
            ->take(5)
            ->get();

        return view('admin.tickets.show', compact('ticket', 'stats', 'relatedTickets'));
    }

    /**
     * Show the form for editing the specified ticket
     */
    public function edit(Ticket $ticket)
    {
        $users = User::where('status', 'active')->get();
        $orders = Order::where('status', 'completed')->get();

        return view('admin.tickets.edit', compact('ticket', 'users', 'orders'));
    }

    /**
     * Update the specified ticket
     */
    public function update(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
            'priority' => 'required|in:low,medium,high,urgent',
            'department' => 'required|in:general,technical,billing,sales,support',
            'status' => 'required|in:open,answered,closed,waiting',
            'order_id' => 'nullable|exists:orders,id',
            'internal_notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $oldData = $ticket->toArray();
            
            $ticket->update($validated);

            // Log the update
            Log::info('Admin updated ticket', [
                'admin_id' => auth()->id(),
                'ticket_id' => $ticket->id,
                'changes' => array_diff_assoc($validated, $oldData),
            ]);

            DB::commit();

            return redirect()->route('admin.tickets.show', $ticket)
                ->with('success', 'Ticket updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update ticket', [
                'error' => $e->getMessage(),
                'ticket_id' => $ticket->id,
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to update ticket. Please try again.');
        }
    }

    /**
     * Remove the specified ticket
     */
    public function destroy(Ticket $ticket)
    {
        try {
            DB::beginTransaction();

            // Check if ticket has replies
            if ($ticket->replies()->exists()) {
                return back()->with('error', 'Cannot delete ticket with replies.');
            }

            $ticketId = $ticket->id;
            $ticket->delete();

            // Log the deletion
            Log::info('Admin deleted ticket', [
                'admin_id' => auth()->id(),
                'ticket_id' => $ticketId,
            ]);

            DB::commit();

            return redirect()->route('admin.tickets.index')
                ->with('success', 'Ticket deleted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete ticket', [
                'error' => $e->getMessage(),
                'ticket_id' => $ticket->id,
            ]);

            return back()->with('error', 'Failed to delete ticket. Please try again.');
        }
    }

    /**
     * Update ticket status
     */
    public function updateStatus(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'status' => 'required|in:open,answered,closed,waiting',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $oldStatus = $ticket->status;
            
            $ticket->update([
                'status' => $validated['status'],
                'internal_notes' => $validated['notes'] ?? $ticket->internal_notes,
            ]);

            // Log the status change
            Log::info('Admin updated ticket status', [
                'admin_id' => auth()->id(),
                'ticket_id' => $ticket->id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            return back()->with('success', 'Ticket status updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update ticket status', [
                'error' => $e->getMessage(),
                'ticket_id' => $ticket->id,
                'status' => $validated['status'],
            ]);

            return back()->with('error', 'Failed to update ticket status. Please try again.');
        }
    }

    /**
     * Add admin reply to ticket
     */
    public function addReply(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:5000',
            'internal' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            $reply = $ticket->replies()->create([
                'user_id' => auth()->id(),
                'admin_id' => auth()->id(),
                'message' => $validated['message'],
                'internal' => $validated['internal'] ?? false,
            ]);

            // Update ticket status if not internal
            if (!($validated['internal'] ?? false)) {
                $ticket->update(['status' => 'answered']);
            }

            // Log the reply
            Log::info('Admin added reply to ticket', [
                'admin_id' => auth()->id(),
                'ticket_id' => $ticket->id,
                'reply_id' => $reply->id,
                'internal' => $validated['internal'] ?? false,
            ]);

            DB::commit();

            return back()->with('success', 'Reply added successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add reply to ticket', [
                'error' => $e->getMessage(),
                'ticket_id' => $ticket->id,
            ]);

            return back()->with('error', 'Failed to add reply. Please try again.');
        }
    }

    /**
     * Bulk update tickets
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'ticket_ids' => 'required|array',
            'ticket_ids.*' => 'exists:tickets,id',
            'action' => 'required|in:open,answered,closed,waiting,delete',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'department' => 'nullable|in:general,technical,billing,sales,support',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $tickets = Ticket::whereIn('id', $validated['ticket_ids'])->get();
            $updatedCount = 0;
            $errors = [];

            foreach ($tickets as $ticket) {
                try {
                    switch ($validated['action']) {
                        case 'open':
                            $ticket->update(['status' => 'open']);
                            $updatedCount++;
                            break;

                        case 'answered':
                            $ticket->update(['status' => 'answered']);
                            $updatedCount++;
                            break;

                        case 'closed':
                            $ticket->update(['status' => 'closed']);
                            $updatedCount++;
                            break;

                        case 'waiting':
                            $ticket->update(['status' => 'waiting']);
                            $updatedCount++;
                            break;

                        case 'delete':
                            if ($ticket->replies()->exists()) {
                                $errors[] = "Ticket #{$ticket->ticket_id} cannot be deleted (has replies)";
                            } else {
                                $ticket->delete();
                                $updatedCount++;
                            }
                            break;
                    }

                    // Update additional fields if provided
                    if ($validated['priority']) {
                        $ticket->update(['priority' => $validated['priority']]);
                    }
                    if ($validated['department']) {
                        $ticket->update(['department' => $validated['department']]);
                    }
                    if ($validated['notes']) {
                        $ticket->update(['internal_notes' => $validated['notes']]);
                    }

                } catch (\Exception $e) {
                    $errors[] = "Failed to update ticket #{$ticket->ticket_id}: " . $e->getMessage();
                }
            }

            // Log the bulk operation
            Log::info('Admin performed bulk ticket update', [
                'admin_id' => auth()->id(),
                'action' => $validated['action'],
                'total_tickets' => count($validated['ticket_ids']),
                'updated_count' => $updatedCount,
                'errors' => $errors,
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            $message = "Successfully updated {$updatedCount} tickets.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to perform bulk ticket update', [
                'error' => $e->getMessage(),
                'action' => $validated['action'],
                'ticket_ids' => $validated['ticket_ids'],
            ]);

            return back()->with('error', 'Failed to perform bulk update. Please try again.');
        }
    }

    /**
     * Export tickets to CSV
     */
    public function export(Request $request)
    {
        $query = Ticket::with(['user', 'order']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('department')) {
            $query->where('department', $request->department);
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $tickets = $query->get();

        $filename = 'tickets_' . now()->format('Y-m_d_H-i-s') . '.csv';

        return response()->stream(function () use ($tickets) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'Ticket ID', 'Subject', 'User', 'Email', 'Status', 'Priority', 
                'Department', 'Created', 'Updated', 'Replies', 'Order ID'
            ]);

            // CSV data
            foreach ($tickets as $ticket) {
                fputcsv($file, [
                    $ticket->ticket_id,
                    $ticket->subject,
                    $ticket->user->username ?? 'N/A',
                    $ticket->user->email ?? 'N/A',
                    $ticket->status,
                    $ticket->priority,
                    $ticket->department,
                    $ticket->created_at->format('Y-m-d H:i:s'),
                    $ticket->updated_at->format('Y-m-d H:i:s'),
                    $ticket->replies_count ?? 0,
                    $ticket->order_id ?? 'N/A',
                ]);
            }

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Get ticket analytics
     */
    public function analytics(Request $request)
    {
        $dateRange = $request->get('date_range', '30');
        $dateFrom = $request->get('date_from', now()->subDays($dateRange)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        $analytics = [
            'overview' => $this->getTicketOverview($dateFrom, $dateTo),
            'byStatus' => $this->getTicketsByStatus($dateFrom, $dateTo),
            'byPriority' => $this->getTicketsByPriority($dateFrom, $dateTo),
            'byDepartment' => $this->getTicketsByDepartment($dateFrom, $dateTo),
            'responseTime' => $this->getResponseTimeAnalytics($dateFrom, $dateTo),
            'trends' => $this->getTicketTrends($dateFrom, $dateTo),
        ];

        return view('admin.tickets.analytics', compact('analytics', 'dateFrom', 'dateTo', 'dateRange'));
    }

    /**
     * Get ticket statistics
     */
    private function getTicketStats($ticketId = null)
    {
        if ($ticketId) {
            // Individual ticket stats
            $ticket = Ticket::find($ticketId);
            return [
                'total_replies' => $ticket->replies()->count(),
                'admin_replies' => $ticket->replies()->whereNotNull('admin_id')->count(),
                'user_replies' => $ticket->replies()->whereNull('admin_id')->count(),
                'days_open' => $ticket->created_at->diffInDays(now()),
                'last_reply_days' => $ticket->updated_at->diffInDays(now()),
            ];
        }

        // Overall ticket stats
        return [
            'total' => Ticket::count(),
            'open' => Ticket::where('status', 'open')->count(),
            'answered' => Ticket::where('status', 'answered')->count(),
            'closed' => Ticket::where('status', 'closed')->count(),
            'waiting' => Ticket::where('status', 'waiting')->count(),
            'urgent' => Ticket::where('priority', 'urgent')->count(),
            'high' => Ticket::where('priority', 'high')->count(),
            'medium' => Ticket::where('priority', 'medium')->count(),
            'low' => Ticket::where('priority', 'low')->count(),
        ];
    }

    /**
     * Get ticket overview statistics
     */
    private function getTicketOverview($dateFrom, $dateTo)
    {
        return [
            'total' => Ticket::whereBetween('created_at', [$dateFrom, $dateTo])->count(),
            'resolved' => Ticket::whereBetween('created_at', [$dateFrom, $dateTo])
                ->whereIn('status', ['closed'])->count(),
            'pending' => Ticket::whereBetween('created_at', [$dateFrom, $dateTo])
                ->whereIn('status', ['open', 'waiting'])->count(),
            'avg_response_time' => $this->calculateAverageResponseTime($dateFrom, $dateTo),
        ];
    }

    /**
     * Get tickets by status
     */
    private function getTicketsByStatus($dateFrom, $dateTo)
    {
        return Ticket::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get tickets by priority
     */
    private function getTicketsByPriority($dateFrom, $dateTo)
    {
        return Ticket::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();
    }

    /**
     * Get tickets by department
     */
    private function getTicketsByDepartment($dateFrom, $dateTo)
    {
        return Ticket::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('department, COUNT(*) as count')
            ->groupBy('department')
            ->pluck('count', 'department')
            ->toArray();
    }

    /**
     * Get response time analytics
     */
    private function getResponseTimeAnalytics($dateFrom, $dateTo)
    {
        $tickets = Ticket::whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereHas('replies', function ($query) {
                $query->whereNotNull('admin_id');
            })
            ->get();

        $responseTimes = [];
        foreach ($tickets as $ticket) {
            $firstReply = $ticket->replies()->whereNotNull('admin_id')->first();
            if ($firstReply) {
                $responseTimes[] = $ticket->created_at->diffInHours($firstReply->created_at);
            }
        }

        return [
            'average' => count($responseTimes) > 0 ? round(array_sum($responseTimes) / count($responseTimes), 2) : 0,
            'min' => count($responseTimes) > 0 ? min($responseTimes) : 0,
            'max' => count($responseTimes) > 0 ? max($responseTimes) : 0,
        ];
    }

    /**
     * Get ticket trends
     */
    private function getTicketTrends($dateFrom, $dateTo)
    {
        return Ticket::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * Calculate average response time
     */
    private function calculateAverageResponseTime($dateFrom, $dateTo)
    {
        $tickets = Ticket::whereBetween('created_at', [$dateFrom, $dateTo])
            ->whereHas('replies', function ($query) {
                $query->whereNotNull('admin_id');
            })
            ->get();

        $totalTime = 0;
        $count = 0;

        foreach ($tickets as $ticket) {
            $firstReply = $ticket->replies()->whereNotNull('admin_id')->first();
            if ($firstReply) {
                $totalTime += $ticket->created_at->diffInHours($firstReply->created_at);
                $count++;
            }
        }

        return $count > 0 ? round($totalTime / $count, 2) : 0;
    }
}