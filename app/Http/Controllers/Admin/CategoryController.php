<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Service;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CategoryController extends Controller
{
    /**
     * Display a listing of categories with search, filter, and sort options
     */
    public function index(Request $request)
    {
        $query = Category::withCount(['services', 'orders']);

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by parent category
        if ($request->filled('parent_id')) {
            if ($request->parent_id === 'null') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $request->parent_id);
            }
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

        if (in_array($sortBy, ['name', 'slug', 'status', 'services_count', 'orders_count', 'created_at'])) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $categories = $query->paginate(20);

        // Get category statistics
        $stats = $this->getCategoryStats();

        // Get parent categories for filter
        $parentCategories = Category::whereNull('parent_id')->get();

        return view('admin.categories.index', compact('categories', 'stats', 'parentCategories'));
    }

    /**
     * Show the form for creating a new category
     */
    public function create()
    {
        $parentCategories = Category::whereNull('parent_id')->get();

        return view('admin.categories.create', compact('parentCategories'));
    }

    /**
     * Store a newly created category
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories',
            'slug' => 'required|string|max:255|unique:categories',
            'description' => 'nullable|string|max:1000',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:7',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        try {
            DB::beginTransaction();

            // Handle image upload
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('categories', 'public');
                $validated['image'] = $imagePath;
            }

            // Set default sort order if not provided
            if (empty($validated['sort_order'])) {
                $maxOrder = Category::max('sort_order') ?? 0;
                $validated['sort_order'] = $maxOrder + 1;
            }

            // Create the category
            $category = Category::create($validated);

            // Log the creation
            Log::info('Admin created category', [
                'admin_id' => auth()->id(),
                'category_id' => $category->id,
                'category_name' => $category->name,
                'parent_id' => $category->parent_id,
            ]);

            DB::commit();

            return redirect()->route('admin.categories.show', $category)
                ->with('success', 'Category created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create category', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to create category. Please try again.');
        }
    }

    /**
     * Display the specified category
     */
    public function show(Category $category)
    {
        $category->load(['parent', 'children', 'services']);

        // Get category statistics
        $stats = $this->getCategoryStats($category->id);

        // Get recent services in this category
        $recentServices = Service::where('category_id', $category->id)
            ->latest()
            ->take(10)
            ->get();

        // Get top performing services
        $topServices = Service::where('category_id', $category->id)
            ->withCount(['orders'])
            ->orderByDesc('orders_count')
            ->take(5)
            ->get();

        // Get category performance over time
        $performanceData = $this->getCategoryPerformance($category->id);

        return view('admin.categories.show', compact(
            'category', 
            'stats', 
            'recentServices', 
            'topServices', 
            'performanceData'
        ));
    }

    /**
     * Show the form for editing the specified category
     */
    public function edit(Category $category)
    {
        $parentCategories = Category::whereNull('parent_id')
            ->where('id', '!=', $category->id)
            ->get();

        return view('admin.categories.edit', compact('category', 'parentCategories'));
    }

    /**
     * Update the specified category
     */
    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:categories,name,' . $category->id,
            'slug' => 'required|string|max:255|unique:categories,slug,' . $category->id,
            'description' => 'nullable|string|max:1000',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'boolean',
            'sort_order' => 'nullable|integer|min:0',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'icon' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:7',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        try {
            DB::beginTransaction();

            $oldData = $category->toArray();
            $oldImage = $category->image;

            // Handle image upload
            if ($request->hasFile('image')) {
                $imagePath = $request->file('image')->store('categories', 'public');
                $validated['image'] = $imagePath;

                // Delete old image if exists
                if ($oldImage && Storage::disk('public')->exists($oldImage)) {
                    Storage::disk('public')->delete($oldImage);
                }
            }

            // Prevent circular parent reference
            if ($validated['parent_id'] == $category->id) {
                return back()->withInput()->with('error', 'Category cannot be its own parent.');
            }

            $category->update($validated);

            // Log the update
            Log::info('Admin updated category', [
                'admin_id' => auth()->id(),
                'category_id' => $category->id,
                'changes' => array_diff_assoc($validated, $oldData),
            ]);

            DB::commit();

            return redirect()->route('admin.categories.show', $category)
                ->with('success', 'Category updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update category', [
                'error' => $e->getMessage(),
                'category_id' => $category->id,
                'data' => $validated,
            ]);

            return back()->withInput()->with('error', 'Failed to update category. Please try again.');
        }
    }

    /**
     * Remove the specified category
     */
    public function destroy(Category $category)
    {
        try {
            DB::beginTransaction();

            // Check if category has services
            if ($category->services()->exists()) {
                return back()->with('error', 'Cannot delete category with services.');
            }

            // Check if category has children
            if ($category->children()->exists()) {
                return back()->with('error', 'Cannot delete category with subcategories.');
            }

            // Check if category has orders
            if ($category->orders()->exists()) {
                return back()->with('error', 'Cannot delete category with orders.');
            }

            $categoryId = $category->id;
            $categoryName = $category->name;
            $categoryImage = $category->image;

            $category->delete();

            // Delete category image if exists
            if ($categoryImage && Storage::disk('public')->exists($categoryImage)) {
                Storage::disk('public')->delete($categoryImage);
            }

            // Log the deletion
            Log::info('Admin deleted category', [
                'admin_id' => auth()->id(),
                'category_id' => $categoryId,
                'category_name' => $categoryName,
            ]);

            DB::commit();

            return redirect()->route('admin.categories.index')
                ->with('success', 'Category deleted successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete category', [
                'error' => $e->getMessage(),
                'category_id' => $category->id,
            ]);

            return back()->with('error', 'Failed to delete category. Please try again.');
        }
    }

    /**
     * Toggle category status
     */
    public function toggleStatus(Category $category)
    {
        try {
            DB::beginTransaction();

            $oldStatus = $category->status;
            $category->update(['status' => !$oldStatus]);

            // Log the status change
            Log::info('Admin toggled category status', [
                'admin_id' => auth()->id(),
                'category_id' => $category->id,
                'old_status' => $oldStatus,
                'new_status' => $category->status,
            ]);

            DB::commit();

            return back()->with('success', 'Category status updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle category status', [
                'error' => $e->getMessage(),
                'category_id' => $category->id,
            ]);

            return back()->with('error', 'Failed to update category status. Please try again.');
        }
    }

    /**
     * Update category sort order
     */
    public function updateSortOrder(Request $request, Category $category)
    {
        $validated = $request->validate([
            'sort_order' => 'required|integer|min:0',
        ]);

        try {
            DB::beginTransaction();

            $oldOrder = $category->sort_order;
            $category->update(['sort_order' => $validated['sort_order']]);

            // Log the sort order change
            Log::info('Admin updated category sort order', [
                'admin_id' => auth()->id(),
                'category_id' => $category->id,
                'old_order' => $oldOrder,
                'new_order' => $validated['sort_order'],
            ]);

            DB::commit();

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update category sort order', [
                'error' => $e->getMessage(),
                'category_id' => $category->id,
            ]);

            return response()->json(['success' => false, 'message' => 'Failed to update sort order.'], 500);
        }
    }

    /**
     * Bulk update categories
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'category_ids' => 'required|array',
            'category_ids.*' => 'exists:categories,id',
            'action' => 'required|in:activate,deactivate,delete,move',
            'parent_id' => 'nullable|exists:categories,id',
            'status' => 'nullable|boolean',
        ]);

        try {
            DB::beginTransaction();

            $categories = Category::whereIn('id', $validated['category_ids'])->get();
            $updatedCount = 0;
            $errors = [];

            foreach ($categories as $category) {
                try {
                    switch ($validated['action']) {
                        case 'activate':
                            $category->update(['status' => true]);
                            $updatedCount++;
                            break;

                        case 'deactivate':
                            $category->update(['status' => false]);
                            $updatedCount++;
                            break;

                        case 'delete':
                            if ($category->services()->exists()) {
                                $errors[] = "Category '{$category->name}' cannot be deleted (has services)";
                            } elseif ($category->children()->exists()) {
                                $errors[] = "Category '{$category->name}' cannot be deleted (has subcategories)";
                            } elseif ($category->orders()->exists()) {
                                $errors[] = "Category '{$category->name}' cannot be deleted (has orders)";
                            } else {
                                $category->delete();
                                $updatedCount++;
                            }
                            break;

                        case 'move':
                            if ($validated['parent_id']) {
                                if ($validated['parent_id'] == $category->id) {
                                    $errors[] = "Category '{$category->name}' cannot be moved to itself";
                                } else {
                                    $category->update(['parent_id' => $validated['parent_id']]);
                                    $updatedCount++;
                                }
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Failed to update category '{$category->name}': " . $e->getMessage();
                }
            }

            // Log the bulk operation
            Log::info('Admin performed bulk category update', [
                'admin_id' => auth()->id(),
                'action' => $validated['action'],
                'total_categories' => count($validated['category_ids']),
                'updated_count' => $updatedCount,
                'errors' => $errors,
                'parent_id' => $validated['parent_id'] ?? null,
            ]);

            DB::commit();

            $message = "Successfully updated {$updatedCount} categories.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to perform bulk category update', [
                'error' => $e->getMessage(),
                'action' => $validated['action'],
                'category_ids' => $validated['category_ids'],
            ]);

            return back()->with('error', 'Failed to perform bulk update. Please try again.');
        }
    }

    /**
     * Export categories to CSV
     */
    public function export(Request $request)
    {
        $query = Category::with(['parent']);

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('parent_id')) {
            if ($request->parent_id === 'null') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $request->parent_id);
            }
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        $categories = $query->get();

        $filename = 'categories_' . now()->format('Y-m_d_H-i-s') . '.csv';

        return response()->stream(function () use ($categories) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'ID', 'Name', 'Slug', 'Description', 'Parent Category', 'Status', 
                'Sort Order', 'Services Count', 'Orders Count', 'Created', 'Updated'
            ]);

            // CSV data
            foreach ($categories as $category) {
                fputcsv($file, [
                    $category->id,
                    $category->name,
                    $category->slug,
                    $category->description ?? 'N/A',
                    $category->parent->name ?? 'None',
                    $category->status ? 'Active' : 'Inactive',
                    $category->sort_order ?? 0,
                    $category->services_count ?? 0,
                    $category->orders_count ?? 0,
                    $category->created_at->format('Y-m-d H:i:s'),
                    $category->updated_at->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($file);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Get category analytics
     */
    public function analytics(Request $request)
    {
        $dateRange = $request->get('date_range', '30');
        $dateFrom = $request->get('date_from', now()->subDays($dateRange)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        $analytics = [
            'overview' => $this->getCategoryOverview($dateFrom, $dateTo),
            'byStatus' => $this->getCategoriesByStatus($dateFrom, $dateTo),
            'performance' => $this->getCategoryPerformanceOverview($dateFrom, $dateTo),
            'trends' => $this->getCategoryTrends($dateFrom, $dateTo),
            'topPerformers' => $this->getTopPerformingCategories($dateFrom, $dateTo),
        ];

        return view('admin.categories.analytics', compact('analytics', 'dateFrom', 'dateTo', 'dateRange'));
    }

    /**
     * Get category statistics
     */
    private function getCategoryStats($categoryId = null)
    {
        if ($categoryId) {
            // Individual category stats
            $category = Category::find($categoryId);
            return [
                'total_services' => $category->services()->count(),
                'active_services' => $category->services()->where('status', true)->count(),
                'total_orders' => $category->orders()->count(),
                'total_revenue' => $category->orders()->where('status', 'completed')->sum('total_amount'),
                'avg_order_value' => $category->orders()->where('status', 'completed')->avg('total_amount'),
                'subcategories' => $category->children()->count(),
            ];
        }

        // Overall category stats
        return [
            'total' => Category::count(),
            'active' => Category::where('status', true)->count(),
            'inactive' => Category::where('status', false)->count(),
            'with_services' => Category::has('services')->count(),
            'without_services' => Category::doesntHave('services')->count(),
            'parent_categories' => Category::whereNull('parent_id')->count(),
            'subcategories' => Category::whereNotNull('parent_id')->count(),
        ];
    }

    /**
     * Get category overview statistics
     */
    private function getCategoryOverview($dateFrom, $dateTo)
    {
        return [
            'total' => Category::whereBetween('created_at', [$dateFrom, $dateTo])->count(),
            'active' => Category::whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('status', true)->count(),
            'with_services' => Category::whereBetween('created_at', [$dateFrom, $dateTo])
                ->has('services')->count(),
            'parent_categories' => Category::whereBetween('created_at', [$dateFrom, $dateTo])
                ->whereNull('parent_id')->count(),
        ];
    }

    /**
     * Get categories by status
     */
    private function getCategoriesByStatus($dateFrom, $dateTo)
    {
        return Category::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get category performance overview
     */
    private function getCategoryPerformanceOverview($dateFrom, $dateTo)
    {
        return Category::whereBetween('created_at', [$dateFrom, $dateTo])
            ->withCount(['services', 'orders'])
            ->withSum('orders as total_revenue', 'total_amount')
            ->get()
            ->map(function ($category) {
                return [
                    'name' => $category->name,
                    'services_count' => $category->services_count,
                    'orders_count' => $category->orders_count,
                    'total_revenue' => $category->total_revenue ?? 0,
                ];
            })
            ->toArray();
    }

    /**
     * Get category trends
     */
    private function getCategoryTrends($dateFrom, $dateTo)
    {
        return Category::whereBetween('created_at', [$dateFrom, $dateTo])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    /**
     * Get top performing categories
     */
    private function getTopPerformingCategories($dateFrom, $dateTo)
    {
        return Category::whereBetween('created_at', [$dateFrom, $dateTo])
            ->withCount(['orders'])
            ->withSum('orders as total_revenue', 'total_amount')
            ->orderByDesc('orders_count')
            ->limit(10)
            ->get()
            ->map(function ($category) {
                return [
                    'name' => $category->name,
                    'orders_count' => $category->orders_count,
                    'total_revenue' => $category->total_revenue ?? 0,
                ];
            })
            ->toArray();
    }

    /**
     * Get individual category performance
     */
    private function getCategoryPerformance($categoryId)
    {
        $category = Category::find($categoryId);
        
        return [
            'monthly_orders' => $category->orders()
                ->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()])
                ->selectRaw('MONTH(created_at) as month, COUNT(*) as count')
                ->groupBy('month')
                ->pluck('count', 'month')
                ->toArray(),
            'monthly_revenue' => $category->orders()
                ->where('status', 'completed')
                ->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()])
                ->selectRaw('MONTH(created_at) as month, SUM(total_amount) as revenue')
                ->groupBy('month')
                ->pluck('revenue', 'month')
                ->toArray(),
        ];
    }
}