<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    /**
     * Display a listing of services with advanced filtering
     */
    public function index(Request $request)
    {
        $query = Service::with(['category', 'provider']);

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('api_service_id', 'like', "%{$search}%")
                  ->orWhereHas('category', function ($categoryQuery) use ($search) {
                      $categoryQuery->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('provider', function ($providerQuery) use ($search) {
                      $providerQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by provider
        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }

        // Filter by price range
        if ($request->filled('min_price')) {
            $query->where('price', '>=', $request->min_price);
        }
        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        // Filter by reseller price range
        if ($request->filled('min_reseller_price')) {
            $query->where('reseller_price', '>=', $request->min_reseller_price);
        }
        if ($request->filled('max_reseller_price')) {
            $query->where('reseller_price', '<=', $request->max_reseller_price);
        }

        // Filter by quantity range
        if ($request->filled('min_quantity')) {
            $query->where('min_quantity', '>=', $request->min_quantity);
        }
        if ($request->filled('max_quantity')) {
            $query->where('max_quantity', '<=', $request->max_quantity);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 25);
        $services = $query->paginate($perPage);

        // Get filter options
        $categories = Category::orderBy('name')->get();
        $providers = Provider::orderBy('name')->get();
        $types = ['subscription', 'one_time', 'recurring'];

        // Get overall statistics
        $stats = $this->getServiceStats();

        return view('admin.services.index', compact(
            'services',
            'categories',
            'providers',
            'types',
            'stats'
        ));
    }

    /**
     * Show the form for creating a new service
     */
    public function create()
    {
        $categories = Category::orderBy('name')->get();
        $providers = Provider::where('status', true)->orderBy('name')->get();
        $types = ['subscription', 'one_time', 'recurring'];

        return view('admin.services.create', compact('categories', 'providers', 'types'));
    }

    /**
     * Store a newly created service
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:services',
            'description' => 'nullable|string|max:1000',
            'category_id' => 'required|exists:categories,id',
            'provider_id' => 'required|exists:providers,id',
            'api_service_id' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'reseller_price' => 'nullable|numeric|min:0',
            'min_quantity' => 'required|integer|min:1',
            'max_quantity' => 'required|integer|min:1|gte:min_quantity',
            'type' => 'required|in:subscription,one_time,recurring',
            'status' => 'boolean',
            'features' => 'nullable|array',
            'features.*' => 'string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'drip_feed' => 'boolean',
            'refill' => 'boolean',
            'cancel' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            // Handle image upload
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('services', 'public');
                $validated['image'] = $imagePath;
            }

            // Set default reseller price if not provided
            if (empty($validated['reseller_price'])) {
                $validated['reseller_price'] = $validated['price'];
            }

            // Create the service
            $service = Service::create($validated);

            // Log the creation
            Log::info('Admin created service', [
                'admin_id' => auth()->id(),
                'service_id' => $service->id,
                'service_name' => $service->name,
                'category_id' => $service->category_id,
                'provider_id' => $service->provider_id,
                'price' => $service->price,
            ]);

            DB::commit();

            return redirect()->route('admin.services.show', $service)
                ->with('success', 'Service created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create service', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to create service. Please try again.');
        }
    }

    /**
     * Display the specified service
     */
    public function show(Service $service)
    {
        $service->load(['category', 'provider']);

        // Get service statistics
        $serviceStats = $this->getIndividualServiceStats($service);

        // Get recent orders for this service
        $recentOrders = Order::where('service_id', $service->id)
            ->with(['user', 'provider'])
            ->latest()
            ->take(10)
            ->get();

        // Get top users for this service
        $topUsers = User::withCount(['orders' => function ($query) use ($service) {
                $query->where('service_id', $service->id);
            }])
            ->withSum(['orders' => function ($query) use ($service) {
                $query->where('service_id', $service->id);
            }], 'total_amount')
            ->orderBy('orders_count', 'desc')
            ->take(5)
            ->get();

        // Get related services in same category
        $relatedServices = Service::where('category_id', $service->category_id)
            ->where('id', '!=', $service->id)
            ->where('status', true)
            ->take(5)
            ->get();

        return view('admin.services.show', compact(
            'service',
            'serviceStats',
            'recentOrders',
            'topUsers',
            'relatedServices'
        ));
    }

    /**
     * Show the form for editing the specified service
     */
    public function edit(Service $service)
    {
        $categories = Category::orderBy('name')->get();
        $providers = Provider::where('status', true)->orderBy('name')->get();
        $types = ['subscription', 'one_time', 'recurring'];

        return view('admin.services.edit', compact('service', 'categories', 'providers', 'types'));
    }

    /**
     * Update the specified service
     */
    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('services')->ignore($service->id)],
            'description' => 'nullable|string|max:1000',
            'category_id' => 'required|exists:categories,id',
            'provider_id' => 'required|exists:providers,id',
            'api_service_id' => 'nullable|string|max:255',
            'price' => 'required|numeric|min:0',
            'reseller_price' => 'nullable|numeric|min:0',
            'min_quantity' => 'required|integer|min:1',
            'max_quantity' => 'required|integer|min:1|gte:min_quantity',
            'type' => 'required|in:subscription,one_time,recurring',
            'status' => 'boolean',
            'features' => 'nullable|array',
            'features.*' => 'string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'drip_feed' => 'boolean',
            'refill' => 'boolean',
            'cancel' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            // Store old values for logging
            $oldValues = $service->toArray();
            $changes = [];

            // Handle image upload
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($service->image && Storage::disk('public')->exists($service->image)) {
                    Storage::disk('public')->delete($service->image);
                }
                
                $imagePath = $request->file('image')->store('services', 'public');
                $validated['image'] = $imagePath;
            }

            // Set default reseller price if not provided
            if (empty($validated['reseller_price'])) {
                $validated['reseller_price'] = $validated['price'];
            }

            // Check for changes
            foreach ($validated as $key => $value) {
                if ($service->$key != $value) {
                    $changes[$key] = [
                        'old' => $service->$key,
                        'new' => $value
                    ];
                }
            }

            // Update the service
            $service->update($validated);

            // Log the changes
            if (!empty($changes)) {
                Log::info('Admin updated service', [
                    'admin_id' => auth()->id(),
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'changes' => $changes,
                ]);
            }

            DB::commit();

            return redirect()->route('admin.services.show', $service)
                ->with('success', 'Service updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update service', [
                'error' => $e->getMessage(),
                'service_id' => $service->id,
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to update service. Please try again.');
        }
    }

    /**
     * Remove the specified service
     */
    public function destroy(Service $service)
    {
        try {
            // Check if service can be deleted
            $hasOrders = Order::where('service_id', $service->id)->exists();
            if ($hasOrders) {
                return back()->with('error', 'Cannot delete service with existing orders.');
            }

            DB::beginTransaction();

            // Delete image if exists
            if ($service->image && Storage::disk('public')->exists($service->image)) {
                Storage::disk('public')->delete($service->image);
            }

            // Log the deletion
            Log::info('Admin deleted service', [
                'admin_id' => auth()->id(),
                'service_id' => $service->id,
                'service_name' => $service->name,
                'category_id' => $service->category_id,
                'provider_id' => $service->provider_id,
            ]);

            $service->delete();

            DB::commit();

            return redirect()->route('admin.services.index')
                ->with('success', 'Service deleted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete service', [
                'error' => $e->getMessage(),
                'service_id' => $service->id,
            ]);

            return back()->with('error', 'Failed to delete service. Please try again.');
        }
    }

    /**
     * Toggle service status
     */
    public function toggleStatus(Service $service)
    {
        try {
            DB::beginTransaction();

            $oldStatus = $service->status;
            $newStatus = !$oldStatus;

            $service->update(['status' => $newStatus]);

            // Log the status change
            Log::info('Admin toggled service status', [
                'admin_id' => auth()->id(),
                'service_id' => $service->id,
                'service_name' => $service->name,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            DB::commit();

            $statusText = $newStatus ? 'activated' : 'deactivated';
            return back()->with('success', "Service {$statusText} successfully.");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle service status', [
                'error' => $e->getMessage(),
                'service_id' => $service->id,
            ]);

            return back()->with('error', 'Failed to toggle service status. Please try again.');
        }
    }

    /**
     * Update service pricing
     */
    public function updatePricing(Request $request, Service $service)
    {
        $validated = $request->validate([
            'price' => 'required|numeric|min:0',
            'reseller_price' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            $oldPrice = $service->price;
            $oldResellerPrice = $service->reseller_price;

            // Set default reseller price if not provided
            if (empty($validated['reseller_price'])) {
                $validated['reseller_price'] = $validated['price'];
            }

            $service->update([
                'price' => $validated['price'],
                'reseller_price' => $validated['reseller_price'],
            ]);

            // Log the pricing change
            Log::info('Admin updated service pricing', [
                'admin_id' => auth()->id(),
                'service_id' => $service->id,
                'service_name' => $service->name,
                'old_price' => $oldPrice,
                'new_price' => $validated['price'],
                'old_reseller_price' => $oldResellerPrice,
                'new_reseller_price' => $validated['reseller_price'],
                'reason' => $validated['reason'] ?? null,
            ]);

            DB::commit();

            return back()->with('success', 'Service pricing updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update service pricing', [
                'error' => $e->getMessage(),
                'service_id' => $service->id,
                'data' => $validated,
            ]);

            return back()->with('error', 'Failed to update service pricing. Please try again.');
        }
    }

    /**
     * Bulk update services
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'service_ids' => 'required|array',
            'service_ids.*' => 'exists:services,id',
            'action' => 'required|in:activate,deactivate,update_category,update_provider,update_pricing,delete',
            'category_id' => 'required_if:action,update_category|exists:categories,id',
            'provider_id' => 'required_if:action,update_provider|exists:providers,id',
            'price' => 'required_if:action,update_pricing|numeric|min:0',
            'reseller_price' => 'nullable|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            $services = Service::whereIn('id', $validated['service_ids'])->get();
            $updatedCount = 0;
            $errors = [];

            foreach ($services as $service) {
                try {
                    switch ($validated['action']) {
                        case 'activate':
                            $service->update(['status' => true]);
                            $updatedCount++;
                            break;

                        case 'deactivate':
                            $service->update(['status' => false]);
                            $updatedCount++;
                            break;

                        case 'update_category':
                            $service->update(['category_id' => $validated['category_id']]);
                            $updatedCount++;
                            break;

                        case 'update_provider':
                            $service->update(['provider_id' => $validated['provider_id']]);
                            $updatedCount++;
                            break;

                        case 'update_pricing':
                            $resellerPrice = $validated['reseller_price'] ?? $validated['price'];
                            $service->update([
                                'price' => $validated['price'],
                                'reseller_price' => $resellerPrice,
                            ]);
                            $updatedCount++;
                            break;

                        case 'delete':
                            $hasOrders = Order::where('service_id', $service->id)->exists();
                            if ($hasOrders) {
                                $errors[] = "Service '{$service->name}' cannot be deleted (has orders)";
                            } else {
                                // Delete image if exists
                                if ($service->image && Storage::disk('public')->exists($service->image)) {
                                    Storage::disk('public')->delete($service->image);
                                }
                                $service->delete();
                                $updatedCount++;
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Failed to update service '{$service->name}': " . $e->getMessage();
                }
            }

            // Log the bulk operation
            Log::info('Admin performed bulk service update', [
                'admin_id' => auth()->id(),
                'action' => $validated['action'],
                'total_services' => count($validated['service_ids']),
                'updated_count' => $updatedCount,
                'errors' => $errors,
                'reason' => $validated['reason'] ?? null,
            ]);

            DB::commit();

            $message = "Successfully updated {$updatedCount} services.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to perform bulk service update', [
                'error' => $e->getMessage(),
                'action' => $validated['action'],
                'service_ids' => $validated['service_ids'],
            ]);

            return back()->with('error', 'Failed to perform bulk update. Please try again.');
        }
    }

    /**
     * Export services to CSV
     */
    public function export(Request $request)
    {
        $query = Service::with(['category', 'provider']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->provider_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $services = $query->orderBy('name')->get();

        $filename = 'services_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($services) {
            $file = fopen('php://output', 'w');

            // Headers
            fputcsv($file, [
                'Service ID', 'Name', 'Description', 'Category', 'Provider', 'API Service ID',
                'Price', 'Reseller Price', 'Min Quantity', 'Max Quantity', 'Type', 'Status',
                'Features', 'Drip Feed', 'Refill', 'Cancel', 'Created At', 'Updated At'
            ]);

            // Data
            foreach ($services as $service) {
                fputcsv($file, [
                    $service->id,
                    $service->name,
                    $service->description,
                    $service->category->name ?? 'N/A',
                    $service->provider->name ?? 'N/A',
                    $service->api_service_id,
                    $service->price,
                    $service->reseller_price,
                    $service->min_quantity,
                    $service->max_quantity,
                    $service->type,
                    $service->status ? 'Active' : 'Inactive',
                    $service->features ? implode(', ', $service->features) : '',
                    $service->drip_feed ? 'Yes' : 'No',
                    $service->refill ? 'Yes' : 'No',
                    $service->cancel ? 'Yes' : 'No',
                    $service->created_at,
                    $service->updated_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Display service analytics
     */
    public function analytics(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        // Get service statistics
        $serviceStats = $this->getServiceStats($dateFrom, $dateTo);

        // Get services by status
        $servicesByStatus = $this->getServicesByStatus($dateFrom, $dateTo);

        // Get services by category
        $servicesByCategory = $this->getServicesByCategory($dateFrom, $dateTo);

        // Get services by provider
        $servicesByProvider = $this->getServicesByProvider($dateFrom, $dateTo);

        // Get top performing services
        $topServices = $this->getTopServices($dateFrom, $dateTo);

        // Get price distribution
        $priceDistribution = $this->getPriceDistribution($dateFrom, $dateTo);

        // Get service performance trends
        $performanceTrends = $this->getPerformanceTrends($dateFrom, $dateTo);

        return view('admin.services.analytics', compact(
            'serviceStats',
            'servicesByStatus',
            'servicesByCategory',
            'servicesByProvider',
            'topServices',
            'priceDistribution',
            'performanceTrends',
            'dateFrom',
            'dateTo'
        ));
    }

    /**
     * Get overall service statistics
     */
    private function getServiceStats($dateFrom = null, $dateTo = null)
    {
        $query = Service::query();

        if ($dateFrom && $dateTo) {
            $query->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        }

        $totalServices = $query->count();
        $activeServices = (clone $query)->where('status', true)->count();
        $inactiveServices = (clone $query)->where('status', false)->count();

        $totalRevenue = Order::where('status', 'completed');
        if ($dateFrom && $dateTo) {
            $totalRevenue->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        }
        $totalRevenue = $totalRevenue->sum('total_amount');

        $avgPrice = $query->avg('price') ?? 0;
        $avgResellerPrice = $query->avg('reseller_price') ?? 0;

        return [
            'total_services' => $totalServices,
            'active_services' => $activeServices,
            'inactive_services' => $inactiveServices,
            'total_revenue' => $totalRevenue,
            'average_price' => round($avgPrice, 2),
            'average_reseller_price' => round($avgResellerPrice, 2),
        ];
    }

    /**
     * Get individual service statistics
     */
    private function getIndividualServiceStats(Service $service)
    {
        $totalOrders = Order::where('service_id', $service->id)->count();
        $completedOrders = Order::where('service_id', $service->id)
            ->where('status', 'completed')
            ->count();
        $totalRevenue = Order::where('service_id', $service->id)
            ->where('status', 'completed')
            ->sum('total_amount');

        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        $completionRate = $totalOrders > 0 ? ($completedOrders / $totalOrders) * 100 : 0;

        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'total_revenue' => $totalRevenue,
            'average_order_value' => round($avgOrderValue, 2),
            'completion_rate' => round($completionRate, 2),
        ];
    }

    /**
     * Get services by status
     */
    private function getServicesByStatus($dateFrom, $dateTo)
    {
        return Service::selectRaw('status, COUNT(*) as count')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();
    }

    /**
     * Get services by category
     */
    private function getServicesByCategory($dateFrom, $dateTo)
    {
        return Service::join('categories', 'services.category_id', '=', 'categories.id')
            ->selectRaw('categories.name as category_name, COUNT(*) as count')
            ->whereBetween('services.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('count', 'desc')
            ->get();
    }

    /**
     * Get services by provider
     */
    private function getServicesByProvider($dateFrom, $dateTo)
    {
        return Service::join('providers', 'services.provider_id', '=', 'providers.id')
            ->selectRaw('providers.name as provider_name, COUNT(*) as count')
            ->whereBetween('services.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('providers.id', 'providers.name')
            ->orderBy('count', 'desc')
            ->get();
    }

    /**
     * Get top performing services
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
     * Get price distribution
     */
    private function getPriceDistribution($dateFrom, $dateTo)
    {
        $priceRanges = [
            '0-10' => Service::whereBetween('price', [0, 10])->count(),
            '10-50' => Service::whereBetween('price', [10, 50])->count(),
            '50-100' => Service::whereBetween('price', [50, 100])->count(),
            '100-500' => Service::whereBetween('price', [100, 500])->count(),
            '500+' => Service::where('price', '>', 500)->count(),
        ];

        return $priceRanges;
    }

    /**
     * Get performance trends
     */
    private function getPerformanceTrends($dateFrom, $dateTo)
    {
        $dailyOrders = Order::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as total_amount')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $dailyServices = Service::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'daily_orders' => $dailyOrders,
            'daily_services' => $dailyServices,
        ];
    }
}