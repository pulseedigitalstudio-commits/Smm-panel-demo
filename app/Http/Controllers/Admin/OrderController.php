<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\Service;
use App\Models\Provider;
use App\Models\Category;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class OrderController extends Controller
{
    /**
     * Display a listing of orders with advanced filtering
     */
    public function index(Request $request)
    {
        $query = Order::with(['user', 'service', 'provider', 'category']);

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('link', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('username', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  })
                  ->orWhereHas('service', function ($serviceQuery) use ($search) {
                      $serviceQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by service
        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }

        // Filter by provider
        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->whereHas('service', function ($q) use ($request) {
                $q->where('category_id', $request->category_id);
            });
        }

        // Filter by amount range
        if ($request->filled('min_amount')) {
            $query->where('total_amount', '>=', $request->min_amount);
        }
        if ($request->filled('max_amount')) {
            $query->where('total_amount', '<=', $request->max_amount);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Filter by completion date
        if ($request->filled('completed_from')) {
            $query->where('completed_at', '>=', $request->completed_from . ' 00:00:00');
        }
        if ($request->filled('completed_to')) {
            $query->where('completed_at', '<=', $request->completed_to . ' 23:59:59');
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 25);
        $orders = $query->paginate($perPage);

        // Get filter options
        $users = User::select('id', 'username', 'email')->orderBy('username')->get();
        $services = Service::select('id', 'name')->orderBy('name')->get();
        $providers = Provider::select('id', 'name')->orderBy('name')->get();
        $categories = Category::select('id', 'name')->orderBy('name')->get();

        // Get overall statistics
        $stats = $this->getOrderStats();

        return view('admin.orders.index', compact(
            'orders',
            'users',
            'services',
            'providers',
            'categories',
            'stats'
        ));
    }

    /**
     * Show the form for creating a new order
     */
    public function create()
    {
        $users = User::where('status', 'active')->orderBy('username')->get();
        $services = Service::where('status', true)->with(['category', 'provider'])->orderBy('name')->get();
        $providers = Provider::where('status', true)->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('admin.orders.create', compact('users', 'services', 'providers', 'categories'));
    }

    /**
     * Store a newly created order
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'service_id' => 'required|exists:services,id',
            'provider_id' => 'required|exists:providers,id',
            'link' => 'required|url',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'status' => 'required|in:pending,processing,completed,failed,cancelled',
        ]);

        try {
            DB::beginTransaction();

            // Generate order number
            $validated['order_number'] = $this->generateOrderNumber();

            // Set start time
            $validated['start_time'] = now();

            // Create the order
            $order = Order::create($validated);

            // Log the creation
            Log::info('Admin created order', [
                'admin_id' => auth()->id(),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
                'service_id' => $order->service_id,
                'amount' => $order->total_amount,
            ]);

            DB::commit();

            return redirect()->route('admin.orders.show', $order)
                ->with('success', 'Order created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create order', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to create order. Please try again.');
        }
    }

    /**
     * Display the specified order
     */
    public function show(Order $order)
    {
        $order->load(['user', 'service', 'provider', 'category']);

        // Get order statistics
        $orderStats = $this->getIndividualOrderStats($order);

        // Get related orders from same user
        $relatedOrders = Order::where('user_id', $order->user_id)
            ->where('id', '!=', $order->id)
            ->with(['service', 'provider'])
            ->latest()
            ->take(10)
            ->get();

        // Get user's recent payments
        $recentPayments = Payment::where('user_id', $order->user_id)
            ->latest()
            ->take(5)
            ->get();

        return view('admin.orders.show', compact('order', 'orderStats', 'relatedOrders', 'recentPayments'));
    }

    /**
     * Show the form for editing the specified order
     */
    public function edit(Order $order)
    {
        $users = User::where('status', 'active')->orderBy('username')->get();
        $services = Service::where('status', true)->with(['category', 'provider'])->orderBy('name')->get();
        $providers = Provider::where('status', true)->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('admin.orders.edit', compact('order', 'users', 'services', 'providers', 'categories'));
    }

    /**
     * Update the specified order
     */
    public function update(Request $request, Order $order)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'service_id' => 'required|exists:services,id',
            'provider_id' => 'required|exists:providers,id',
            'link' => 'required|url',
            'quantity' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'status' => 'required|in:pending,processing,completed,failed,cancelled',
            'notes' => 'nullable|string|max:1000',
            'start_time' => 'nullable|date',
            'finish_time' => 'nullable|date',
            'completed_at' => 'nullable|date',
        ]);

        try {
            DB::beginTransaction();

            // Store old values for logging
            $oldValues = $order->toArray();
            $changes = [];

            // Check for changes
            foreach ($validated as $key => $value) {
                if ($order->$key != $value) {
                    $changes[$key] = [
                        'old' => $order->$key,
                        'new' => $value
                    ];
                }
            }

            // Update the order
            $order->update($validated);

            // Log the changes
            if (!empty($changes)) {
                Log::info('Admin updated order', [
                    'admin_id' => auth()->id(),
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'changes' => $changes,
                ]);
            }

            DB::commit();

            return redirect()->route('admin.orders.show', $order)
                ->with('success', 'Order updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update order', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to update order. Please try again.');
        }
    }

    /**
     * Remove the specified order
     */
    public function destroy(Order $order)
    {
        try {
            // Check if order can be deleted
            if ($order->status === 'completed' && $order->completed_at) {
                return back()->with('error', 'Cannot delete completed orders.');
            }

            // Check for related payments
            $hasPayments = Payment::where('order_id', $order->id)->exists();
            if ($hasPayments) {
                return back()->with('error', 'Cannot delete order with related payments.');
            }

            DB::beginTransaction();

            // Log the deletion
            Log::info('Admin deleted order', [
                'admin_id' => auth()->id(),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'user_id' => $order->user_id,
                'amount' => $order->total_amount,
                'status' => $order->status,
            ]);

            $order->delete();

            DB::commit();

            return redirect()->route('admin.orders.index')
                ->with('success', 'Order deleted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete order', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ]);

            return back()->with('error', 'Failed to delete order. Please try again.');
        }
    }

    /**
     * Update order status
     */
    public function updateStatus(Request $request, Order $order)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,processing,completed,failed,cancelled',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $oldStatus = $order->status;
            $newStatus = $validated['status'];

            // Update status
            $order->update([
                'status' => $newStatus,
                'notes' => $validated['notes'] ?? $order->notes,
            ]);

            // Set completion time if status is completed
            if ($newStatus === 'completed' && $oldStatus !== 'completed') {
                $order->update([
                    'completed_at' => now(),
                    'finish_time' => now(),
                ]);
            }

            // Log the status change
            Log::info('Admin updated order status', [
                'admin_id' => auth()->id(),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            return back()->with('success', 'Order status updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update order status', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'status' => $validated['status'],
            ]);

            return back()->with('error', 'Failed to update order status. Please try again.');
        }
    }

    /**
     * Process order (mark as processing)
     */
    public function processOrder(Order $order)
    {
        if ($order->status !== 'pending') {
            return back()->with('error', 'Only pending orders can be processed.');
        }

        try {
            DB::beginTransaction();

            $order->update([
                'status' => 'processing',
                'start_time' => now(),
            ]);

            // Log the processing
            Log::info('Admin processed order', [
                'admin_id' => auth()->id(),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            DB::commit();

            return back()->with('success', 'Order marked as processing.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process order', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ]);

            return back()->with('error', 'Failed to process order. Please try again.');
        }
    }

    /**
     * Complete order
     */
    public function completeOrder(Request $request, Order $order)
    {
        if (!in_array($order->status, ['pending', 'processing'])) {
            return back()->with('error', 'Only pending or processing orders can be completed.');
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $order->update([
                'status' => 'completed',
                'completed_at' => now(),
                'finish_time' => now(),
                'notes' => $validated['notes'] ?? $order->notes,
            ]);

            // Log the completion
            Log::info('Admin completed order', [
                'admin_id' => auth()->id(),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            return back()->with('success', 'Order marked as completed.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to complete order', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ]);

            return back()->with('error', 'Failed to complete order. Please try again.');
        }
    }

    /**
     * Cancel order
     */
    public function cancelOrder(Request $request, Order $order)
    {
        if (!in_array($order->status, ['pending', 'processing'])) {
            return back()->with('error', 'Only pending or processing orders can be cancelled.');
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $order->update([
                'status' => 'cancelled',
                'notes' => $validated['notes'] ?? $order->notes,
            ]);

            // Log the cancellation
            Log::info('Admin cancelled order', [
                'admin_id' => auth()->id(),
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            return back()->with('success', 'Order cancelled successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to cancel order', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
            ]);

            return back()->with('error', 'Failed to cancel order. Please try again.');
        }
    }

    /**
     * Bulk update orders
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:orders,id',
            'action' => 'required|in:process,complete,cancel,fail,delete',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $orders = Order::whereIn('id', $validated['order_ids'])->get();
            $updatedCount = 0;
            $errors = [];

            foreach ($orders as $order) {
                try {
                    switch ($validated['action']) {
                        case 'process':
                            if ($order->status === 'pending') {
                                $order->update([
                                    'status' => 'processing',
                                    'start_time' => now(),
                                ]);
                                $updatedCount++;
                            } else {
                                $errors[] = "Order #{$order->order_number} cannot be processed (status: {$order->status})";
                            }
                            break;

                        case 'complete':
                            if (in_array($order->status, ['pending', 'processing'])) {
                                $order->update([
                                    'status' => 'completed',
                                    'completed_at' => now(),
                                    'finish_time' => now(),
                                    'notes' => $validated['notes'] ?? $order->notes,
                                ]);
                                $updatedCount++;
                            } else {
                                $errors[] = "Order #{$order->order_number} cannot be completed (status: {$order->status})";
                            }
                            break;

                        case 'cancel':
                            if (in_array($order->status, ['pending', 'processing'])) {
                                $order->update([
                                    'status' => 'cancelled',
                                    'notes' => $validated['notes'] ?? $order->notes,
                                ]);
                                $updatedCount++;
                            } else {
                                $errors[] = "Order #{$order->order_number} cannot be cancelled (status: {$order->status})";
                            }
                            break;

                        case 'fail':
                            if (in_array($order->status, ['pending', 'processing'])) {
                                $order->update([
                                    'status' => 'failed',
                                    'notes' => $validated['notes'] ?? $order->notes,
                                ]);
                                $updatedCount++;
                            } else {
                                $errors[] = "Order #{$order->order_number} cannot be failed (status: {$order->status})";
                            }
                            break;

                        case 'delete':
                            if ($order->status === 'completed' && $order->completed_at) {
                                $errors[] = "Order #{$order->order_number} cannot be deleted (completed)";
                            } elseif (Payment::where('order_id', $order->id)->exists()) {
                                $errors[] = "Order #{$order->order_number} cannot be deleted (has payments)";
                            } else {
                                $order->delete();
                                $updatedCount++;
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Failed to update order #{$order->order_number}: " . $e->getMessage();
                }
            }

            // Log the bulk operation
            Log::info('Admin performed bulk order update', [
                'admin_id' => auth()->id(),
                'action' => $validated['action'],
                'total_orders' => count($validated['order_ids']),
                'updated_count' => $updatedCount,
                'errors' => $errors,
                'notes' => $validated['notes'] ?? null,
            ]);

            DB::commit();

            $message = "Successfully updated {$updatedCount} orders.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to perform bulk order update', [
                'error' => $e->getMessage(),
                'action' => $validated['action'],
                'order_ids' => $validated['order_ids'],
            ]);

            return back()->with('error', 'Failed to perform bulk update. Please try again.');
        }
    }

    /**
     * Export orders to CSV
     */
    public function export(Request $request)
    {
        $query = Order::with(['user', 'service', 'provider', 'category']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $orders = $query->orderBy('created_at', 'desc')->get();

        $filename = 'orders_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($orders) {
            $file = fopen('php://output', 'w');

            // Headers
            fputcsv($file, [
                'Order ID', 'Order Number', 'User', 'Email', 'Service', 'Category', 'Provider',
                'Link', 'Quantity', 'Price', 'Total Amount', 'Status', 'Start Time',
                'Finish Time', 'Completed At', 'Notes', 'Created At'
            ]);

            // Data
            foreach ($orders as $order) {
                fputcsv($file, [
                    $order->id,
                    $order->order_number,
                    $order->user->username ?? 'N/A',
                    $order->user->email ?? 'N/A',
                    $order->service->name ?? 'N/A',
                    $order->category->name ?? 'N/A',
                    $order->provider->name ?? 'N/A',
                    $order->link,
                    $order->quantity,
                    $order->price,
                    $order->total_amount,
                    $order->status,
                    $order->start_time,
                    $order->finish_time,
                    $order->completed_at,
                    $order->notes,
                    $order->created_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Display order analytics
     */
    public function analytics(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        // Get order statistics
        $orderStats = $this->getOrderStats($dateFrom, $dateTo);

        // Get orders by status
        $ordersByStatus = $this->getOrdersByStatus($dateFrom, $dateTo);

        // Get orders by day
        $dailyOrders = $this->getDailyOrders($dateFrom, $dateTo);

        // Get orders by category
        $ordersByCategory = $this->getOrdersByCategory($dateFrom, $dateTo);

        // Get orders by service
        $ordersByService = $this->getOrdersByService($dateFrom, $dateTo);

        // Get top performing users
        $topUsers = $this->getTopUsers($dateFrom, $dateTo);

        // Get top performing services
        $topServices = $this->getTopServices($dateFrom, $dateTo);

        // Get processing time analytics
        $processingTime = $this->getProcessingTimeAnalytics($dateFrom, $dateTo);

        return view('admin.orders.analytics', compact(
            'orderStats',
            'ordersByStatus',
            'dailyOrders',
            'ordersByCategory',
            'ordersByService',
            'topUsers',
            'topServices',
            'processingTime',
            'dateFrom',
            'dateTo'
        ));
    }

    /**
     * Get overall order statistics
     */
    private function getOrderStats($dateFrom = null, $dateTo = null)
    {
        $query = Order::query();

        if ($dateFrom && $dateTo) {
            $query->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        }

        $totalOrders = $query->count();
        $totalAmount = $query->sum('total_amount');

        $pendingOrders = (clone $query)->where('status', 'pending')->count();
        $processingOrders = (clone $query)->where('status', 'processing')->count();
        $completedOrders = (clone $query)->where('status', 'completed')->count();
        $failedOrders = (clone $query)->where('status', 'failed')->count();
        $cancelledOrders = (clone $query)->where('status', 'cancelled')->count();

        $avgOrderValue = $totalOrders > 0 ? $totalAmount / $totalOrders : 0;

        return [
            'total_orders' => $totalOrders,
            'total_amount' => $totalAmount,
            'pending_orders' => $pendingOrders,
            'processing_orders' => $processingOrders,
            'completed_orders' => $completedOrders,
            'failed_orders' => $failedOrders,
            'cancelled_orders' => $cancelledOrders,
            'average_order_value' => round($avgOrderValue, 2),
        ];
    }

    /**
     * Get individual order statistics
     */
    private function getIndividualOrderStats(Order $order)
    {
        $userOrders = Order::where('user_id', $order->user_id)->count();
        $userTotalSpent = Order::where('user_id', $order->user_id)->sum('total_amount');
        $userCompletedOrders = Order::where('user_id', $order->user_id)
            ->where('status', 'completed')
            ->count();

        $serviceOrders = Order::where('service_id', $order->service_id)->count();
        $serviceTotalRevenue = Order::where('service_id', $order->service_id)->sum('total_amount');

        return [
            'user_total_orders' => $userOrders,
            'user_total_spent' => $userTotalSpent,
            'user_completed_orders' => $userCompletedOrders,
            'service_total_orders' => $serviceOrders,
            'service_total_revenue' => $serviceTotalRevenue,
        ];
    }

    /**
     * Get orders by status
     */
    private function getOrdersByStatus($dateFrom, $dateTo)
    {
        return Order::selectRaw('status, COUNT(*) as count, SUM(total_amount) as total_amount')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();
    }

    /**
     * Get daily orders
     */
    private function getDailyOrders($dateFrom, $dateTo)
    {
        return Order::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as total_amount')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get orders by category
     */
    private function getOrdersByCategory($dateFrom, $dateTo)
    {
        return Order::join('services', 'orders.service_id', '=', 'services.id')
            ->join('categories', 'services.category_id', '=', 'categories.id')
            ->selectRaw('categories.name as category_name, COUNT(*) as count, SUM(orders.total_amount) as total_amount')
            ->whereBetween('orders.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('count', 'desc')
            ->take(10)
            ->get();
    }

    /**
     * Get orders by service
     */
    private function getOrdersByService($dateFrom, $dateTo)
    {
        return Order::join('services', 'orders.service_id', '=', 'services.id')
            ->selectRaw('services.name as service_name, COUNT(*) as count, SUM(orders.total_amount) as total_amount')
            ->whereBetween('orders.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('services.id', 'services.name')
            ->orderBy('count', 'desc')
            ->take(10)
            ->get();
    }

    /**
     * Get top users by orders
     */
    private function getTopUsers($dateFrom, $dateTo)
    {
        return User::withCount(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            }])
            ->withSum(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            }], 'total_amount')
            ->orderBy('orders_count', 'desc')
            ->take(10)
            ->get();
    }

    /**
     * Get top services by orders
     */
    private function getTopServices($dateFrom, $dateTo)
    {
        return Service::withCount(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            }])
            ->withSum(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            }], 'total_amount')
            ->orderBy('orders_count', 'desc')
            ->take(10)
            ->get();
    }

    /**
     * Get processing time analytics
     */
    private function getProcessingTimeAnalytics($dateFrom, $dateTo)
    {
        $avgProcessingTime = Order::where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) as avg_minutes')
            ->first();

        $fastestOrder = Order::where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->selectRaw('*, TIMESTAMPDIFF(MINUTE, created_at, completed_at) as processing_minutes')
            ->orderBy('processing_minutes')
            ->first();

        $slowestOrder = Order::where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->selectRaw('*, TIMESTAMPDIFF(MINUTE, created_at, completed_at) as processing_minutes')
            ->orderBy('processing_minutes', 'desc')
            ->first();

        return [
            'average_processing_time' => $avgProcessingTime->avg_minutes ?? 0,
            'fastest_order' => $fastestOrder,
            'slowest_order' => $slowestOrder,
        ];
    }

    /**
     * Generate unique order number
     */
    private function generateOrderNumber()
    {
        do {
            $orderNumber = 'ORD-' . strtoupper(uniqid());
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}