<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\Service;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProviderController extends Controller
{
    /**
     * Display a listing of providers with search, filter, and sort options
     */
    public function index(Request $request)
    {
        $query = Provider::withCount(['services', 'orders']);

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('api_url', 'like', "%{$search}%")
                  ->orWhere('contact', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Sort options
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');

        if (in_array($sortBy, ['name', 'email', 'type', 'status', 'services_count', 'orders_count', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $providers = $query->paginate(20);

        // Get provider statistics
        $stats = $this->getProviderStats();

        return view('admin.providers.index', compact('providers', 'stats'));
    }

    /**
     * Show the form for creating a new provider
     */
    public function create()
    {
        return view('admin.providers.create');
    }

    /**
     * Store a newly created provider
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:providers',
            'email' => 'nullable|email|max:255',
            'api_url' => 'required|string|max:255|unique:providers',
            'type' => 'required|in:api,manual',
            'status' => 'boolean',
            'contact' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $provider = Provider::create($validated);

            Log::info('Admin created provider', [
                'admin_id' => auth()->id(),
                'provider_id' => $provider->id,
                'provider_name' => $provider->name,
            ]);

            DB::commit();

            return redirect()->route('admin.providers.show', $provider)
                ->with('success', 'Provider created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create provider', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to create provider. Please try again.');
        }
    }

    /**
     * Display the specified provider
     */
    public function show(Provider $provider)
    {
        $provider->load(['services', 'orders']);

        // Get provider statistics
        $stats = $this->getProviderStats($provider->id);

        // Get recent services
        $recentServices = Service::where('provider_id', $provider->id)
            ->latest()
            ->take(10)
            ->get();

        // Get top services
        $topServices = Service::where('provider_id', $provider->id)
            ->withCount(['orders'])
            ->orderByDesc('orders_count')
            ->take(5)
            ->get();

        // Get provider performance
        $performanceData = $this->getProviderPerformance($provider->id);

        return view('admin.providers.show', compact(
            'provider',
            'stats',
            'recentServices',
            'topServices',
            'performanceData'
        ));
    }

    /**
     * Show the form for editing the specified provider
     */
    public function edit(Provider $provider)
    {
        return view('admin.providers.edit', compact('provider'));
    }

    /**
     * Update the specified provider
     */
    public function update(Request $request, Provider $provider)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:providers,name,' . $provider->id,
            'email' => 'nullable|email|max:255',
            'api_url' => 'required|string|max:255|unique:providers,api_url,' . $provider->id,
            'type' => 'required|in:api,manual',
            'status' => 'boolean',
            'contact' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $oldData = $provider->toArray();
            $provider->update($validated);

            Log::info('Admin updated provider', [
                'admin_id' => auth()->id(),
                'provider_id' => $provider->id,
                'changes' => array_diff_assoc($validated, $oldData),
            ]);

            DB::commit();

            return redirect()->route('admin.providers.show', $provider)
                ->with('success', 'Provider updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update provider', [
                'error' => $e->getMessage(),
                'provider_id' => $provider->id,
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to update provider. Please try again.');
        }
    }

    /**
     * Remove the specified provider
     */
    public function destroy(Provider $provider)
    {
        try {
            DB::beginTransaction();

            // Check if provider has services
            if ($provider->services()->exists()) {
                return back()->with('error', 'Cannot delete provider with services.');
            }

            // Check if provider has orders
            if ($provider->orders()->exists()) {
                return back()->with('error', 'Cannot delete provider with orders.');
            }

            $providerId = $provider->id;
            $providerName = $provider->name;

            $provider->delete();

            Log::info('Admin deleted provider', [
                'admin_id' => auth()->id(),
                'provider_id' => $providerId,
                'provider_name' => $providerName,
            ]);

            DB::commit();

            return redirect()->route('admin.providers.index')
                ->with('success', 'Provider deleted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete provider', [
                'error' => $e->getMessage(),
                'provider_id' => $provider->id,
            ]);

            return back()->with('error', 'Failed to delete provider. Please try again.');
        }
    }

    /**
     * Toggle provider status
     */
    public function toggleStatus(Provider $provider)
    {
        try {
            DB::beginTransaction();

            $oldStatus = $provider->status;
            $provider->update(['status' => !$oldStatus]);

            Log::info('Admin toggled provider status', [
                'admin_id' => auth()->id(),
                'provider_id' => $provider->id,
                'old_status' => $oldStatus,
                'new_status' => $provider->status,
            ]);

            DB::commit();

            return back()->with('success', 'Provider status updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle provider status', [
                'error' => $e->getMessage(),
                'provider_id' => $provider->id,
            ]);

            return back()->with('error', 'Failed to update provider status. Please try again.');
        }
    }

    /**
     * Bulk update providers
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'provider_ids' => 'required|array',
            'provider_ids.*' => 'exists:providers,id',
            'action' => 'required|in:activate,deactivate,delete',
        ]);

        try {
            DB::beginTransaction();

            $providers = Provider::whereIn('id', $validated['provider_ids'])->get();
            $updatedCount = 0;
            $errors = [];

            foreach ($providers as $provider) {
                try {
                    switch ($validated['action']) {
                        case 'activate':
                            $provider->update(['status' => true]);
                            $updatedCount++;
                            break;
                        case 'deactivate':
                            $provider->update(['status' => false]);
                            $updatedCount++;
                            break;
                        case 'delete':
                            if ($provider->services()->exists()) {
                                $errors[] = "Provider '{$provider->name}' cannot be deleted (has services)";
                            } elseif ($provider->orders()->exists()) {
                                $errors[] = "Provider '{$provider->name}' cannot be deleted (has orders)";
                            } else {
                                $provider->delete();
                                $updatedCount++;
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Failed to update provider '{$provider->name}': " . $e->getMessage();
                }
            }

            Log::info('Admin performed bulk provider update', [
                'admin_id' => auth()->id(),
                'action' => $validated['action'],
                'total_providers' => count($validated['provider_ids']),
                'updated_count' => $updatedCount,
                'errors' => $errors,
            ]);

            DB::commit();

            $message = "Successfully updated {$updatedCount} providers.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to perform bulk provider update', [
                'error' => $e->getMessage(),
                'action' => $validated['action'],
                'provider_ids' => $validated['provider_ids'],
            ]);

            return back()->with('error', 'Failed to perform bulk update. Please try again.');
        }
    }

    /**
     * Export providers to CSV
     */
    public function export(Request $request)
    {
        $query = Provider::query();

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $providers = $query->get();

        $filename = 'providers_' . now()->format('Y-m_d_H-i-s') . '.csv';

        return response()->stream(function () use ($providers) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'ID', 'Name', 'Email', 'API URL', 'Type', 'Status', 'Contact', 'Services Count', 'Orders Count', 'Created', 'Updated'
            ]);

            // CSV data
            foreach ($providers as $provider) {
                fputcsv($file, [
                    $provider->id,
                    $provider->name,
                    $provider->email ?? 'N/A',
                    $provider->api_url,
                    $provider->type,
                    $provider->status ? 'Active' : 'Inactive',
                    $provider->contact ?? 'N/A',
                    $provider->services()->count(),
                    $provider->orders()->count(),
                    $provider->created_at->format('Y-m-d H:i:s'),
                    $provider->updated_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Get provider analytics
     */
    public function analytics(Request $request)
    {
        $dateRange = $request->get('date_range', '30');
        $dateFrom = $request->get('date_from', now()->subDays($dateRange)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        $analytics = [
            'overview' => $this->getProviderOverview($dateFrom, $dateTo),
            'byStatus' => $this->getProvidersByStatus($dateFrom, $dateTo),
            'performance' => $this->getProviderPerformanceOverview($dateFrom, $dateTo),
            'trends' => $this->getProviderTrends($dateFrom, $dateTo),
            'topPerformers' => $this->getTopPerformingProviders($dateFrom, $dateTo),
        ];

        return view('admin.providers.analytics', compact('analytics', 'dateFrom', 'dateTo', 'dateRange'));
    }

    /**
     * Get provider statistics
     */
    private function getProviderStats($providerId = null)
    {
        if ($providerId) {
            $provider = Provider::find($providerId);
            return [
                'total_services' => $provider->services()->count(),
                'active_services' => $provider->services()->where('status', true)->count(),
                'total_orders' => $provider->orders()->count(),
                'total_revenue' => $provider->orders()->where('status', 'completed')->sum('total_amount'),
                'avg_order_value' => $provider->orders()->where('status', 'completed')->avg('total_amount'),
            ];
        }

        return [
            'total' => Provider::count(),
            'active' => Provider::where('status', true)->count(),
            'inactive' => Provider::where('status', false)->count(),
            'with_services' => Provider::has('services')->count(),
            'without_services' => Provider::doesntHave('services')->count(),
        ];
    }

    /**
     * Get provider overview statistics
     */
    private function getProviderOverview($dateFrom, $dateTo)
    {
        return [
            'total' => Provider::whereBetween('created_at', [$dateFrom, $dateTo])->count(),
            'active' => Provider::whereBetween('created_at', [$dateFrom, $dateTo])->where('status', true)->count(),
            'with_services' => Provider::whereBetween('created_at', [$dateFrom, $dateTo])->has('services')->count(),
        ];
    }

    /**
     * Get providers by status
     */
    private function getProvidersByStatus($dateFrom, $dateTo)
    {
        return Provider::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get provider performance overview
     */
    private function getProviderPerformanceOverview($dateFrom, $dateTo)
    {
        return Provider::whereBetween('created_at', [$dateFrom, $dateTo])
            ->withCount(['services', 'orders'])
            ->withSum('orders as total_revenue', 'total_amount')
            ->get()
            ->map(function ($provider) {
                return [
                    'name' => $provider->name,
                    'services_count' => $provider->services_count,
                    'orders_count' => $provider->orders_count,
                    'total_revenue' => $provider->total_revenue ?? 0,
                ];
            })
            ->toArray();
    }

    /**
     * Get provider trends
     */
    private function getProviderTrends($dateFrom, $dateTo)
    {
        return Provider::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * Get top performing providers
     */
    private function getTopPerformingProviders($dateFrom, $dateTo)
    {
        return Provider::whereBetween('created_at', [$dateFrom, $dateTo])
            ->withCount(['orders'])
            ->withSum('orders as total_revenue', 'total_amount')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->get()
            ->map(function ($provider) {
                return [
                    'name' => $provider->name,
                    'orders_count' => $provider->orders_count,
                    'total_revenue' => $provider->total_revenue ?? 0,
                ];
            })
            ->toArray();
    }

    /**
     * Get individual provider performance
     */
    private function getProviderPerformance($providerId)
    {
        $provider = Provider::find($providerId);
        
        return [
            'monthly_orders' => $provider->orders()
                ->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()])
                ->selectRaw('MONTH(created_at) as month, COUNT(*) as count')
                ->groupBy('month')
                ->pluck('count', 'month')
                ->toArray(),
            'monthly_revenue' => $provider->orders()
                ->where('status', 'completed')
                ->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()])
                ->selectRaw('MONTH(created_at) as month, SUM(total_amount) as revenue')
                ->groupBy('month')
                ->pluck('revenue', 'month')
                ->toArray(),
        ];
    }
}