<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Service;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Payment;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Display the admin dashboard with comprehensive analytics
     */
    public function index(Request $request)
    {
        $dateRange = $request->get('date_range', '30');
        $dateFrom = $request->get('date_from', now()->subDays($dateRange)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        // Get overall statistics
        $overallStats = $this->getOverallStats();
        
        // Get recent activity
        $recentActivity = $this->getRecentActivity();
        
        // Get performance metrics
        $performanceMetrics = $this->getPerformanceMetrics($dateFrom, $dateTo);
        
        // Get revenue analytics
        $revenueAnalytics = $this->getRevenueAnalytics($dateFrom, $dateTo);
        
        // Get user analytics
        $userAnalytics = $this->getUserAnalytics($dateFrom, $dateTo);
        
        // Get order analytics
        $orderAnalytics = $this->getOrderAnalytics($dateFrom, $dateTo);
        
        // Get service analytics
        $serviceAnalytics = $this->getServiceAnalytics($dateFrom, $dateTo);
        
        // Get provider analytics
        $providerAnalytics = $this->getProviderAnalytics($dateFrom, $dateTo);
        
        // Get ticket analytics
        $ticketAnalytics = $this->getTicketAnalytics($dateFrom, $dateTo);
        
        // Get payment analytics
        $paymentAnalytics = $this->getPaymentAnalytics($dateFrom, $dateTo);
        
        // Get top performers
        $topPerformers = $this->getTopPerformers($dateFrom, $dateTo);
        
        // Get system health
        $systemHealth = $this->getSystemHealth();

        return view('admin.dashboard.index', compact(
            'overallStats',
            'recentActivity',
            'performanceMetrics',
            'revenueAnalytics',
            'userAnalytics',
            'orderAnalytics',
            'serviceAnalytics',
            'providerAnalytics',
            'ticketAnalytics',
            'paymentAnalytics',
            'topPerformers',
            'systemHealth',
            'dateFrom',
            'dateTo',
            'dateRange'
        ));
    }

    /**
     * Get overall system statistics
     */
    private function getOverallStats()
    {
        return [
            'total_users' => User::count(),
            'total_orders' => Order::count(),
            'total_services' => Service::count(),
            'total_categories' => Category::count(),
            'total_providers' => Provider::count(),
            'total_tickets' => Ticket::count(),
            'total_payments' => Payment::count(),
            'total_revenue' => Payment::where('status', 'completed')->sum('amount'),
            'active_users' => User::where('status', 'active')->count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'open_tickets' => Ticket::where('status', 'open')->count(),
            'pending_payments' => Payment::where('status', 'pending')->count(),
        ];
    }

    /**
     * Get recent activity across all entities
     */
    private function getRecentActivity()
    {
        $recentUsers = User::latest()->take(5)->get(['id', 'username', 'email', 'created_at']);
        $recentOrders = Order::with(['user', 'service'])
            ->latest()
            ->take(5)
            ->get(['id', 'user_id', 'service_id', 'total_amount', 'status', 'created_at']);
        $recentPayments = Payment::with(['user'])
            ->latest()
            ->take(5)
            ->get(['id', 'user_id', 'amount', 'status', 'payment_method', 'created_at']);
        $recentTickets = Ticket::with(['user'])
            ->latest()
            ->take(5)
            ->get(['id', 'user_id', 'subject', 'status', 'priority', 'created_at']);

        return [
            'users' => $recentUsers,
            'orders' => $recentOrders,
            'payments' => $recentPayments,
            'tickets' => $recentTickets,
        ];
    }

    /**
     * Get performance metrics for the dashboard
     */
    private function getPerformanceMetrics($dateFrom, $dateTo)
    {
        $startDate = Carbon::parse($dateFrom);
        $endDate = Carbon::parse($dateTo);

        // User growth
        $userGrowth = $this->calculateGrowthRate(
            User::where('created_at', '<', $startDate)->count(),
            User::where('created_at', '<=', $endDate)->count()
        );

        // Order growth
        $orderGrowth = $this->calculateGrowthRate(
            Order::where('created_at', '<', $startDate)->count(),
            Order::where('created_at', '<=', $endDate)->count()
        );

        // Revenue growth
        $revenueGrowth = $this->calculateGrowthRate(
            Payment::where('status', 'completed')
                ->where('created_at', '<', $startDate)
                ->sum('amount'),
            Payment::where('status', 'completed')
                ->where('created_at', '<=', $endDate)
                ->sum('amount')
        );

        // Service growth
        $serviceGrowth = $this->calculateGrowthRate(
            Service::where('created_at', '<', $startDate)->count(),
            Service::where('created_at', '<=', $endDate)->count()
        );

        return [
            'user_growth' => $userGrowth,
            'order_growth' => $orderGrowth,
            'revenue_growth' => $revenueGrowth,
            'service_growth' => $serviceGrowth,
        ];
    }

    /**
     * Get revenue analytics
     */
    private function getRevenueAnalytics($dateFrom, $dateTo)
    {
        // Daily revenue
        $dailyRevenue = Payment::selectRaw('DATE(created_at) as date, SUM(amount) as total_amount')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Revenue by payment method
        $revenueByMethod = Payment::selectRaw('payment_method, SUM(amount) as total_amount, COUNT(*) as count')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->groupBy('payment_method')
            ->orderBy('total_amount', 'desc')
            ->get();

        // Monthly revenue trend
        $monthlyRevenue = Payment::selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(amount) as total_amount')
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        // Average order value
        $avgOrderValue = Order::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->avg('total_amount') ?? 0;

        return [
            'daily_revenue' => $dailyRevenue,
            'revenue_by_method' => $revenueByMethod,
            'monthly_revenue' => $monthlyRevenue,
            'average_order_value' => $avgOrderValue,
        ];
    }

    /**
     * Get user analytics
     */
    private function getUserAnalytics($dateFrom, $dateTo)
    {
        // User registrations by day
        $dailyRegistrations = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Users by status
        $usersByStatus = User::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();

        // Users by role
        $usersByRole = User::selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->orderBy('count', 'desc')
            ->get();

        // Users by country
        $usersByCountry = User::selectRaw('country, COUNT(*) as count')
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderBy('count', 'desc')
            ->take(10)
            ->get();

        // Email verification stats
        $verifiedUsers = User::whereNotNull('email_verified_at')->count();
        $unverifiedUsers = User::whereNull('email_verified_at')->count();

        return [
            'daily_registrations' => $dailyRegistrations,
            'users_by_status' => $usersByStatus,
            'users_by_role' => $usersByRole,
            'users_by_country' => $usersByCountry,
            'verified_users' => $verifiedUsers,
            'unverified_users' => $unverifiedUsers,
        ];
    }

    /**
     * Get order analytics
     */
    private function getOrderAnalytics($dateFrom, $dateTo)
    {
        // Orders by status
        $ordersByStatus = Order::selectRaw('status, COUNT(*) as count, SUM(total_amount) as total_amount')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();

        // Orders by day
        $dailyOrders = Order::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as total_amount')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Orders by service category
        $ordersByCategory = Order::join('services', 'orders.service_id', '=', 'services.id')
            ->join('categories', 'services.category_id', '=', 'categories.id')
            ->selectRaw('categories.name as category_name, COUNT(*) as count, SUM(orders.total_amount) as total_amount')
            ->whereBetween('orders.created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('count', 'desc')
            ->take(10)
            ->get();

        // Average processing time
        $avgProcessingTime = Order::where('status', 'completed')
            ->whereNotNull('completed_at')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) as avg_minutes')
            ->first();

        return [
            'orders_by_status' => $ordersByStatus,
            'daily_orders' => $dailyOrders,
            'orders_by_category' => $ordersByCategory,
            'average_processing_time' => $avgProcessingTime->avg_minutes ?? 0,
        ];
    }

    /**
     * Get service analytics
     */
    private function getServiceAnalytics($dateFrom, $dateTo)
    {
        // Services by status
        $servicesByStatus = Service::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();

        // Services by category
        $servicesByCategory = Service::join('categories', 'services.category_id', '=', 'categories.id')
            ->selectRaw('categories.name as category_name, COUNT(*) as count')
            ->groupBy('categories.id', 'categories.name')
            ->orderBy('count', 'desc')
            ->get();

        // Top selling services
        $topSellingServices = Service::withCount(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }])
            ->withSum(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }], 'total_amount')
            ->orderBy('orders_count', 'desc')
            ->take(10)
            ->get();

        // Service price distribution
        $priceRanges = [
            '0-10' => Service::whereBetween('price', [0, 10])->count(),
            '10-50' => Service::whereBetween('price', [10, 50])->count(),
            '50-100' => Service::whereBetween('price', [50, 100])->count(),
            '100-500' => Service::whereBetween('price', [100, 500])->count(),
            '500+' => Service::where('price', '>', 500)->count(),
        ];

        return [
            'services_by_status' => $servicesByStatus,
            'services_by_category' => $servicesByCategory,
            'top_selling_services' => $topSellingServices,
            'price_distribution' => $priceRanges,
        ];
    }

    /**
     * Get provider analytics
     */
    private function getProviderAnalytics($dateFrom, $dateTo)
    {
        // Providers by status
        $providersByStatus = Provider::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();

        // Top performing providers
        $topProviders = Provider::withCount(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }])
            ->withSum(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }], 'total_amount')
            ->orderBy('orders_count', 'desc')
            ->take(10)
            ->get();

        // Provider balance distribution
        $balanceRanges = [
            '0-100' => Provider::whereBetween('balance', [0, 100])->count(),
            '100-500' => Provider::whereBetween('balance', [100, 500])->count(),
            '500-1000' => Provider::whereBetween('balance', [500, 1000])->count(),
            '1000-5000' => Provider::whereBetween('balance', [1000, 5000])->count(),
            '5000+' => Provider::where('balance', '>', 5000)->count(),
        ];

        return [
            'providers_by_status' => $providersByStatus,
            'top_providers' => $topProviders,
            'balance_distribution' => $balanceRanges,
        ];
    }

    /**
     * Get ticket analytics
     */
    private function getTicketAnalytics($dateFrom, $dateTo)
    {
        // Tickets by status
        $ticketsByStatus = Ticket::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();

        // Tickets by priority
        $ticketsByPriority = Ticket::selectRaw('priority, COUNT(*) as count')
            ->groupBy('priority')
            ->orderBy('count', 'desc')
            ->get();

        // Tickets by day
        $dailyTickets = Ticket::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Average response time
        $avgResponseTime = Ticket::where('status', '!=', 'open')
            ->whereNotNull('assigned_to')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_minutes')
            ->first();

        // Support staff performance
        $staffPerformance = User::role(['admin', 'support'])
            ->withCount(['assignedTickets' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }])
            ->withCount(['resolvedTickets' => function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'resolved')
                    ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }])
            ->orderBy('assigned_tickets_count', 'desc')
            ->take(10)
            ->get();

        return [
            'tickets_by_status' => $ticketsByStatus,
            'tickets_by_priority' => $ticketsByPriority,
            'daily_tickets' => $dailyTickets,
            'average_response_time' => $avgResponseTime->avg_minutes ?? 0,
            'staff_performance' => $staffPerformance,
        ];
    }

    /**
     * Get payment analytics
     */
    private function getPaymentAnalytics($dateFrom, $dateTo)
    {
        // Payments by status
        $paymentsByStatus = Payment::selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();

        // Payments by method
        $paymentsByMethod = Payment::selectRaw('payment_method, COUNT(*) as count, SUM(amount) as total_amount')
            ->groupBy('payment_method')
            ->orderBy('count', 'desc')
            ->get();

        // Daily payment amounts
        $dailyPayments = Payment::selectRaw('DATE(created_at) as date, SUM(amount) as total_amount, COUNT(*) as count')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Payment success rate
        $totalPayments = Payment::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])->count();
        $successfulPayments = Payment::whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->where('status', 'completed')
            ->count();

        $successRate = $totalPayments > 0 ? round(($successfulPayments / $totalPayments) * 100, 2) : 0;

        return [
            'payments_by_status' => $paymentsByStatus,
            'payments_by_method' => $paymentsByMethod,
            'daily_payments' => $dailyPayments,
            'success_rate' => $successRate,
        ];
    }

    /**
     * Get top performers across all entities
     */
    private function getTopPerformers($dateFrom, $dateTo)
    {
        // Top users by orders
        $topUsersByOrders = User::withCount(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }])
            ->withSum(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }], 'total_amount')
            ->orderBy('orders_count', 'desc')
            ->take(5)
            ->get();

        // Top users by balance
        $topUsersByBalance = User::orderBy('balance', 'desc')
            ->take(5)
            ->get(['id', 'username', 'balance', 'status']);

        // Top services by orders
        $topServicesByOrders = Service::withCount(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }])
            ->withSum(['orders' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
            }], 'total_amount')
            ->orderBy('orders_count', 'desc')
            ->take(5)
            ->get();

        // Top categories by orders
        $topCategoriesByOrders = Category::withCount(['services' => function ($query) use ($dateFrom, $dateTo) {
                $query->whereHas('orders', function ($orderQuery) use ($dateFrom, $dateTo) {
                    $orderQuery->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
                });
            }])
            ->orderBy('services_count', 'desc')
            ->take(5)
            ->get();

        return [
            'top_users_by_orders' => $topUsersByOrders,
            'top_users_by_balance' => $topUsersByBalance,
            'top_services_by_orders' => $topServicesByOrders,
            'top_categories_by_orders' => $topCategoriesByOrders,
        ];
    }

    /**
     * Get system health metrics
     */
    private function getSystemHealth()
    {
        // Database performance
        $dbPerformance = [
            'total_tables' => DB::select('SHOW TABLES'),
            'slow_queries' => 0, // Would need slow query log enabled
        ];

        // System metrics
        $systemMetrics = [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];

        // Error rates (if logging is configured)
        $errorRates = [
            'recent_errors' => 0, // Would need error logging
            'system_alerts' => 0,
        ];

        return [
            'database' => $dbPerformance,
            'system' => $systemMetrics,
            'errors' => $errorRates,
        ];
    }

    /**
     * Calculate growth rate percentage
     */
    private function calculateGrowthRate($oldValue, $newValue)
    {
        if ($oldValue == 0) {
            return $newValue > 0 ? 100 : 0;
        }

        return round((($newValue - $oldValue) / $oldValue) * 100, 2);
    }

    /**
     * Get real-time dashboard updates via AJAX
     */
    public function getRealTimeUpdates()
    {
        $updates = [
            'new_users' => User::where('created_at', '>=', now()->subMinutes(5))->count(),
            'new_orders' => Order::where('created_at', '>=', now()->subMinutes(5))->count(),
            'new_tickets' => Ticket::where('created_at', '>=', now()->subMinutes(5))->count(),
            'new_payments' => Payment::where('created_at', '>=', now()->subMinutes(5))->count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'open_tickets' => Ticket::where('status', 'open')->count(),
            'pending_payments' => Payment::where('status', 'pending')->count(),
        ];

        return response()->json($updates);
    }

    /**
     * Export dashboard data to CSV
     */
    public function exportDashboardData(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        $filename = 'dashboard_data_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($dateFrom, $dateTo) {
            $file = fopen('php://output', 'w');

            // Overall statistics
            fputcsv($file, ['Overall Statistics']);
            fputcsv($file, ['Metric', 'Value']);
            fputcsv($file, ['Total Users', User::count()]);
            fputcsv($file, ['Total Orders', Order::count()]);
            fputcsv($file, ['Total Revenue', Payment::where('status', 'completed')->sum('amount')]);
            fputcsv($file, ['Active Users', User::where('status', 'active')->count()]);
            fputcsv($file, ['Pending Orders', Order::where('status', 'pending')->count()]);
            fputcsv($file, ['Open Tickets', Ticket::where('status', 'open')->count()]);

            fputcsv($file, []); // Empty row

            // Daily user registrations
            fputcsv($file, ['Daily User Registrations']);
            fputcsv($file, ['Date', 'Count']);
            $dailyUsers = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            foreach ($dailyUsers as $user) {
                fputcsv($file, [$user->date, $user->count]);
            }

            fputcsv($file, []); // Empty row

            // Daily orders
            fputcsv($file, ['Daily Orders']);
            fputcsv($file, ['Date', 'Count', 'Total Amount']);
            $dailyOrders = Order::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as total_amount')
                ->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59'])
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            foreach ($dailyOrders as $order) {
                fputcsv($file, [$order->date, $order->count, $order->total_amount]);
            }

            fputcsv($file, []); // Empty row

            // Top performing services
            fputcsv($file, ['Top Performing Services']);
            fputcsv($file, ['Service Name', 'Orders Count', 'Total Revenue']);
            $topServices = Service::withCount(['orders' => function ($query) use ($dateFrom, $dateTo) {
                    $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
                }])
                ->withSum(['orders' => function ($query) use ($dateFrom, $dateTo) {
                    $query->whereBetween('created_at', [$dateFrom, $dateTo . ' 23:59:59']);
                }], 'total_amount')
                ->orderBy('orders_count', 'desc')
                ->take(20)
                ->get();

            foreach ($topServices as $service) {
                fputcsv($file, [$service->name, $service->orders_count, $service->orders_sum_total_amount ?? 0]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}