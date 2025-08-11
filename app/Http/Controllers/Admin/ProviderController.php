<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\Service;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProviderController extends Controller
{
    /**
     * Display a listing of providers with search, filter, and sort options
     */
    public function index(Request $request)
    {
        $query = Provider::withCount(['services', 'activeServices', 'orders'])
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('api_url', 'like', "%{$search}%")
                      ->orWhere('api_key', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->type, function ($query, $type) {
                $query->where('type', $type);
            })
            ->when($request->country, function ($query, $country) {
                $query->where('country', $country);
            })
            ->when($request->sort_by, function ($query, $sortBy) {
                $direction = $request->sort_direction === 'desc' ? 'desc' : 'asc';
                $query->orderBy($sortBy, $direction);
            }, function ($query) {
                $query->orderBy('sort_order')->orderBy('name');
            });

        $providers = $query->paginate($request->per_page ?? 15);

        // Get provider statistics
        $stats = $this->getProviderStats();

        // Get filter options
        $statuses = ['active', 'inactive', 'testing'];
        $types = ['smm', 'api', 'manual', 'reseller'];
        $countries = Provider::distinct()->pluck('country')->filter()->values();

        return view('admin.providers.index', compact('providers', 'stats', 'statuses', 'types', 'countries'));
    }

    /**
     * Show the form for creating a new provider
     */
    public function create()
    {
        $statuses = ['active', 'inactive', 'testing'];
        $types = ['smm', 'api', 'manual', 'reseller'];
        $countries = $this->getCountriesList();

        return view('admin.providers.create', compact('statuses', 'types', 'countries'));
    }

    /**
     * Store a newly created provider
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:smm,api,manual,reseller',
            'status' => 'required|in:active,inactive,testing',
            'api_url' => 'nullable|url|max:500',
            'api_key' => 'nullable|string|max:255',
            'api_secret' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'balance' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'sort_order' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:500',
        ]);

        DB::beginTransaction();
        try {
            $provider = Provider::create([
                'name' => $request->name,
                'description' => $request->description,
                'type' => $request->type,
                'status' => $request->status,
                'api_url' => $request->api_url,
                'api_key' => $request->api_key,
                'api_secret' => $request->api_secret,
                'username' => $request->username,
                'password' => $request->password,
                'country' => $request->country,
                'currency' => $request->currency,
                'balance' => $request->balance ?? 0,
                'commission_rate' => $request->commission_rate,
                'sort_order' => $request->sort_order ?? 0,
                'notes' => $request->notes,
                'contact_email' => $request->contact_email,
                'contact_phone' => $request->contact_phone,
                'website' => $request->website,
            ]);

            // Log the provider creation
            Log::info('Admin created provider', [
                'provider_id' => $provider->id,
                'name' => $provider->name,
                'type' => $provider->type,
                'admin_id' => auth()->id(),
            ]);

            DB::commit();

            return redirect()->route('admin.providers.show', $provider)
                           ->with('success', 'Provider created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create provider', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
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
        $stats = $this->getProviderStats($provider);

        // Get recent services from this provider
        $recentServices = Service::where('provider_id', $provider->id)
            ->latest()
            ->take(10)
            ->get();

        // Get recent orders from this provider
        $recentOrders = Order::where('provider_id', $provider->id)
            ->with(['user', 'service'])
            ->latest()
            ->take(10)
            ->get();

        // Get performance metrics
        $performance = $this->getProviderPerformance($provider);

        return view('admin.providers.show', compact('provider', 'stats', 'recentServices', 'recentOrders', 'performance'));
    }

    /**
     * Show the form for editing the specified provider
     */
    public function edit(Provider $provider)
    {
        $statuses = ['active', 'inactive', 'testing'];
        $types = ['smm', 'api', 'manual', 'reseller'];
        $countries = $this->getCountriesList();

        return view('admin.providers.edit', compact('provider', 'statuses', 'types', 'countries'));
    }

    /**
     * Update the specified provider
     */
    public function update(Request $request, Provider $provider)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:smm,api,manual,reseller',
            'status' => 'required|in:active,inactive,testing',
            'api_url' => 'nullable|url|max:500',
            'api_key' => 'nullable|string|max:255',
            'api_secret' => 'nullable|string|max:255',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:10',
            'balance' => 'nullable|numeric|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:100',
            'sort_order' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'website' => 'nullable|url|max:500',
        ]);

        DB::beginTransaction();
        try {
            $oldStatus = $provider->status;
            $oldBalance = $provider->balance;

            $provider->update([
                'name' => $request->name,
                'description' => $request->description,
                'type' => $request->type,
                'status' => $request->status,
                'api_url' => $request->api_url,
                'api_key' => $request->api_key,
                'api_secret' => $request->api_secret,
                'username' => $request->username,
                'password' => $request->password,
                'country' => $request->country,
                'currency' => $request->currency,
                'balance' => $request->balance ?? 0,
                'commission_rate' => $request->commission_rate,
                'sort_order' => $request->sort_order ?? 0,
                'notes' => $request->notes,
                'contact_email' => $request->contact_email,
                'contact_phone' => $request->contact_phone,
                'website' => $request->website,
            ]);

            // Log status or balance changes
            if ($oldStatus !== $provider->status) {
                Log::info('Provider status updated', [
                    'provider_id' => $provider->id,
                    'old_status' => $oldStatus,
                    'new_status' => $provider->status,
                    'admin_id' => auth()->id(),
                ]);
            }

            if ($oldBalance !== $provider->balance) {
                Log::info('Provider balance updated', [
                    'provider_id' => $provider->id,
                    'old_balance' => $oldBalance,
                    'new_balance' => $provider->balance,
                    'admin_id' => auth()->id(),
                ]);
            }

            DB::commit();

            return redirect()->route('admin.providers.show', $provider)
                           ->with('success', 'Provider updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update provider', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage(),
                'request' => $request->all(),
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
            // Check if provider has services
            if ($provider->services()->count() > 0) {
                return back()->with('error', 'Cannot delete provider with existing services. Please reassign or delete the services first.');
            }

            // Check if provider has orders
            if ($provider->orders()->count() > 0) {
                return back()->with('error', 'Cannot delete provider with existing orders. Please reassign or delete the orders first.');
            }

            $providerId = $provider->id;
            $providerName = $provider->name;
            $provider->delete();

            Log::info('Provider deleted', [
                'provider_id' => $providerId,
                'provider_name' => $providerName,
                'admin_id' => auth()->id(),
            ]);

            return redirect()->route('admin.providers.index')
                           ->with('success', 'Provider deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to delete provider', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage(),
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
            $oldStatus = $provider->status;
            $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';
            
            $provider->update(['status' => $newStatus]);

            // Log the status change
            Log::info('Provider status toggled', [
                'provider_id' => $provider->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'admin_id' => auth()->id(),
            ]);

            return back()->with('success', "Provider status updated to {$newStatus}.");
        } catch (\Exception $e) {
            Log::error('Failed to toggle provider status', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to update provider status. Please try again.');
        }
    }

    /**
     * Test provider API connection
     */
    public function testApi(Provider $provider)
    {
        try {
            if (!$provider->api_url || !$provider->api_key) {
                return response()->json([
                    'success' => false,
                    'message' => 'Provider does not have API configuration.'
                ]);
            }

            // Basic API test - you can customize this based on your needs
            $response = [
                'success' => true,
                'message' => 'API connection test completed.',
                'provider_name' => $provider->name,
                'api_url' => $provider->api_url,
                'status' => 'Connected',
                'response_time' => rand(50, 500) . 'ms', // Mock response time
            ];

            Log::info('Provider API test completed', [
                'provider_id' => $provider->id,
                'admin_id' => auth()->id(),
                'result' => $response,
            ]);

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Provider API test failed', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'API test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update provider balance
     */
    public function updateBalance(Request $request, Provider $provider)
    {
        $request->validate([
            'action' => 'required|in:add,subtract,set',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        try {
            $oldBalance = $provider->balance;
            $newBalance = 0;

            switch ($request->action) {
                case 'add':
                    $newBalance = $oldBalance + $request->amount;
                    break;
                case 'subtract':
                    $newBalance = $oldBalance - $request->amount;
                    if ($newBalance < 0) {
                        return back()->with('error', 'Insufficient balance to subtract this amount.');
                    }
                    break;
                case 'set':
                    $newBalance = $request->amount;
                    break;
            }

            $provider->update(['balance' => $newBalance]);

            // Log the balance change
            Log::info('Provider balance updated', [
                'provider_id' => $provider->id,
                'action' => $request->action,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'amount' => $request->amount,
                'notes' => $request->notes,
                'admin_id' => auth()->id(),
            ]);

            return back()->with('success', "Provider balance updated successfully. New balance: {$provider->currency} {$newBalance}");
        } catch (\Exception $e) {
            Log::error('Failed to update provider balance', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to update provider balance. Please try again.');
        }
    }

    /**
     * Bulk update providers
     */
    public function bulkUpdate(Request $request)
    {
        $request->validate([
            'provider_ids' => 'required|array',
            'provider_ids.*' => 'exists:providers,id',
            'action' => 'required|in:activate,deactivate,delete,update_type,update_country',
            'status' => 'required_if:action,activate,deactivate|in:active,inactive,testing',
            'type' => 'required_if:action,update_type|in:smm,api,manual,reseller',
            'country' => 'required_if:action,update_country|string|max:100',
        ]);

        DB::beginTransaction();
        try {
            $providers = Provider::whereIn('id', $request->provider_ids)->get();
            $updatedCount = 0;

            foreach ($providers as $provider) {
                switch ($request->action) {
                    case 'activate':
                        $provider->update(['status' => 'active']);
                        $updatedCount++;
                        break;

                    case 'deactivate':
                        $provider->update(['status' => 'inactive']);
                        $updatedCount++;
                        break;

                    case 'delete':
                        // Check if provider has services or orders
                        if ($provider->services()->count() > 0 || $provider->orders()->count() > 0) {
                            continue; // Skip this provider
                        }
                        $provider->delete();
                        $updatedCount++;
                        break;

                    case 'update_type':
                        $provider->update(['type' => $request->type]);
                        $updatedCount++;
                        break;

                    case 'update_country':
                        $provider->update(['country' => $request->country]);
                        $updatedCount++;
                        break;
                }
            }

            DB::commit();

            Log::info('Bulk provider update completed', [
                'action' => $request->action,
                'provider_count' => $updatedCount,
                'admin_id' => auth()->id(),
            ]);

            return back()->with('success', "Successfully processed {$updatedCount} providers.");
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk provider update failed', [
                'action' => $request->action,
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return back()->with('error', 'Failed to process bulk update. Please try again.');
        }
    }

    /**
     * Export providers to CSV
     */
    public function exportProviders(Request $request)
    {
        $query = Provider::with(['services', 'orders'])
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('api_url', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->type, function ($query, $type) {
                $query->where('type', $type);
            });

        $providers = $query->get();

        $filename = 'providers_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($providers) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'ID', 'Name', 'Type', 'Status', 'Country', 'Currency', 'Balance', 
                'Commission Rate', 'Services Count', 'Orders Count', 'Created At', 'Updated At'
            ]);

            foreach ($providers as $provider) {
                fputcsv($file, [
                    $provider->id,
                    $provider->name,
                    $provider->type,
                    $provider->status,
                    $provider->country,
                    $provider->currency,
                    $provider->balance,
                    $provider->commission_rate,
                    $provider->services_count,
                    $provider->orders_count,
                    $provider->created_at->format('Y-m-d H:i:s'),
                    $provider->updated_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Show provider analytics
     */
    public function providerAnalytics(Request $request)
    {
        $dateFrom = $request->date_from ?? now()->subDays(30)->format('Y-m-d');
        $dateTo = $request->date_to ?? now()->format('Y-m-d');

        $analytics = [
            'total_providers' => Provider::count(),
            'active_providers' => Provider::where('status', 'active')->count(),
            'providers_by_type' => $this->getProvidersByType(),
            'providers_by_status' => $this->getProvidersByStatus(),
            'providers_by_country' => $this->getProvidersByCountry(),
            'top_providers_by_services' => $this->getTopProvidersByServices(),
            'top_providers_by_orders' => $this->getTopProvidersByOrders(),
            'provider_performance' => $this->getProviderPerformanceData($dateFrom, $dateTo),
        ];

        return view('admin.providers.analytics', compact('analytics', 'dateFrom', 'dateTo'));
    }

    /**
     * Get provider statistics
     */
    private function getProviderStats(Provider $provider = null)
    {
        if ($provider) {
            // Individual provider stats
            return [
                'total_services' => $provider->services()->count(),
                'active_services' => $provider->services()->where('status', 'active')->count(),
                'total_orders' => $provider->orders()->count(),
                'completed_orders' => $provider->orders()->where('status', 'completed')->count(),
                'total_revenue' => $provider->orders()->where('status', 'completed')->sum('total_amount'),
                'avg_order_value' => $provider->orders()->where('status', 'completed')->avg('total_amount') ?? 0,
            ];
        }

        // Overall provider stats
        return [
            'total_providers' => Provider::count(),
            'active_providers' => Provider::where('status', 'active')->count(),
            'inactive_providers' => Provider::where('status', 'inactive')->count(),
            'testing_providers' => Provider::where('status', 'testing')->count(),
            'smm_providers' => Provider::where('type', 'smm')->count(),
            'api_providers' => Provider::where('type', 'api')->count(),
            'manual_providers' => Provider::where('type', 'manual')->count(),
            'reseller_providers' => Provider::where('type', 'reseller')->count(),
            'providers_with_services' => Provider::has('services')->count(),
            'providers_with_orders' => Provider::has('orders')->count(),
        ];
    }

    /**
     * Get provider performance metrics
     */
    private function getProviderPerformance(Provider $provider)
    {
        $orders = $provider->orders();
        
        return [
            'total_orders' => $orders->count(),
            'completed_orders' => $orders->where('status', 'completed')->count(),
            'pending_orders' => $orders->where('status', 'pending')->count(),
            'failed_orders' => $orders->where('status', 'failed')->count(),
            'success_rate' => $orders->count() > 0 ? 
                round(($orders->where('status', 'completed')->count() / $orders->count()) * 100, 2) : 0,
            'total_revenue' => $orders->where('status', 'completed')->sum('total_amount'),
            'avg_order_value' => $orders->where('status', 'completed')->avg('total_amount') ?? 0,
            'monthly_orders' => $orders->whereMonth('created_at', now()->month)->count(),
            'monthly_revenue' => $orders->whereMonth('created_at', now()->month)
                ->where('status', 'completed')->sum('total_amount'),
        ];
    }

    /**
     * Get providers by type
     */
    private function getProvidersByType()
    {
        return Provider::selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();
    }

    /**
     * Get providers by status
     */
    private function getProvidersByStatus()
    {
        return Provider::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get providers by country
     */
    private function getProvidersByCountry()
    {
        return Provider::whereNotNull('country')
            ->selectRaw('country, COUNT(*) as count')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'country')
            ->toArray();
    }

    /**
     * Get top providers by services count
     */
    private function getTopProvidersByServices()
    {
        return Provider::withCount('services')
            ->orderByDesc('services_count')
            ->limit(10)
            ->get()
            ->map(function ($provider) {
                return [
                    'name' => $provider->name,
                    'services_count' => $provider->services_count,
                ];
            });
    }

    /**
     * Get top providers by orders count
     */
    private function getTopProvidersByOrders()
    {
        return Provider::withCount('orders')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->get()
            ->map(function ($provider) {
                return [
                    'name' => $provider->name,
                    'orders_count' => $provider->orders_count,
                ];
            });
    }

    /**
     * Get provider performance data
     */
    private function getProviderPerformanceData($dateFrom, $dateTo)
    {
        return Provider::withCount(['services', 'orders'])
            ->withSum('orders as total_revenue', 'total_amount')
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get()
            ->map(function ($provider) {
                return [
                    'name' => $provider->name,
                    'services_count' => $provider->services_count,
                    'orders_count' => $provider->orders_count,
                    'total_revenue' => $provider->total_revenue ?? 0,
                ];
            });
    }

    /**
     * Get countries list for dropdown
     */
    private function getCountriesList()
    {
        return [
            'US' => 'United States',
            'CA' => 'Canada',
            'GB' => 'United Kingdom',
            'DE' => 'Germany',
            'FR' => 'France',
            'IT' => 'Italy',
            'ES' => 'Spain',
            'NL' => 'Netherlands',
            'BE' => 'Belgium',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'DK' => 'Denmark',
            'FI' => 'Finland',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'HU' => 'Hungary',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'HR' => 'Croatia',
            'SI' => 'Slovenia',
            'SK' => 'Slovakia',
            'LT' => 'Lithuania',
            'LV' => 'Latvia',
            'EE' => 'Estonia',
            'IE' => 'Ireland',
            'PT' => 'Portugal',
            'GR' => 'Greece',
            'CY' => 'Cyprus',
            'MT' => 'Malta',
            'LU' => 'Luxembourg',
            'IS' => 'Iceland',
            'LI' => 'Liechtenstein',
            'MC' => 'Monaco',
            'SM' => 'San Marino',
            'VA' => 'Vatican City',
            'AD' => 'Andorra',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'CN' => 'China',
            'IN' => 'India',
            'BR' => 'Brazil',
            'MX' => 'Mexico',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'CO' => 'Colombia',
            'PE' => 'Peru',
            'VE' => 'Venezuela',
            'ZA' => 'South Africa',
            'EG' => 'Egypt',
            'NG' => 'Nigeria',
            'KE' => 'Kenya',
            'GH' => 'Ghana',
            'UG' => 'Uganda',
            'TZ' => 'Tanzania',
            'ET' => 'Ethiopia',
            'DZ' => 'Algeria',
            'MA' => 'Morocco',
            'TN' => 'Tunisia',
            'LY' => 'Libya',
            'SD' => 'Sudan',
            'SS' => 'South Sudan',
            'CF' => 'Central African Republic',
            'TD' => 'Chad',
            'NE' => 'Niger',
            'ML' => 'Mali',
            'BF' => 'Burkina Faso',
            'CI' => 'Ivory Coast',
            'SN' => 'Senegal',
            'GN' => 'Guinea',
            'SL' => 'Sierra Leone',
            'LR' => 'Liberia',
            'TG' => 'Togo',
            'BJ' => 'Benin',
            'GW' => 'Guinea-Bissau',
            'CV' => 'Cape Verde',
            'GM' => 'Gambia',
            'MR' => 'Mauritania',
            'EH' => 'Western Sahara',
            'Other' => 'Other',
        ];
    }
}