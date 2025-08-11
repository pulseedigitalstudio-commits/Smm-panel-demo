<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with(['roles']);

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }
        if ($request->filled('country')) {
            $query->where('country', $request->country);
        }
        if ($request->filled('min_balance')) {
            $query->where('balance', '>=', $request->min_balance);
        }
        if ($request->filled('max_balance')) {
            $query->where('balance', '<=', $request->max_balance);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 20);
        $users = $query->paginate($perPage);

        // Get filter options
        $roles = Role::orderBy('name')->get();
        $countries = $this->getCountriesList();
        $statuses = ['active', 'inactive', 'suspended'];

        return view('admin.users.index', compact('users', 'roles', 'countries', 'statuses'));
    }

    public function create()
    {
        $roles = Role::orderBy('name')->get();
        $countries = $this->getCountriesList();
        $statuses = ['active', 'inactive', 'suspended'];

        return view('admin.users.create', compact('roles', 'countries', 'statuses'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'country' => 'required|string|max:100',
            'status' => 'required|in:active,inactive,suspended',
            'balance' => 'nullable|numeric|min:0',
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,id',
        ]);

        try {
            DB::beginTransaction();

            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'country' => $validated['country'],
                'status' => $validated['status'],
                'balance' => $validated['balance'] ?? 0,
                'email_verified_at' => now(),
            ]);

            $user->syncRoles($validated['roles']);

            Log::info('Admin created user', [
                'admin_id' => auth()->id(),
                'user_id' => $user->id,
                'username' => $user->username,
            ]);

            DB::commit();

            return redirect()->route('admin.users.show', $user)
                ->with('success', 'User created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to create user. Please try again.');
        }
    }

    public function show(User $user)
    {
        $user->load(['orders.service', 'orders.provider', 'tickets']);

        $recentOrders = $user->orders()->with(['service', 'provider'])->latest()->take(10)->get();
        $recentTickets = $user->tickets()->latest()->take(10)->get();

        return view('admin.users.show', compact('user', 'recentOrders', 'recentTickets'));
    }

    public function edit(User $user)
    {
        $roles = Role::orderBy('name')->get();
        $countries = $this->getCountriesList();
        $statuses = ['active', 'inactive', 'suspended'];

        return view('admin.users.edit', compact('user', 'roles', 'countries', 'statuses'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'country' => 'required|string|max:100',
            'status' => 'required|in:active,inactive,suspended',
            'balance' => 'required|numeric|min:0',
            'roles' => 'required|array',
            'roles.*' => 'exists:roles,id',
        ]);

        try {
            DB::beginTransaction();

            $user->update($validated);
            $user->syncRoles($validated['roles']);

            Log::info('Admin updated user', [
                'admin_id' => auth()->id(),
                'user_id' => $user->id,
                'username' => $user->username,
            ]);

            DB::commit();

            return redirect()->route('admin.users.show', $user)
                ->with('success', 'User updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to update user. Please try again.');
        }
    }

    public function destroy(User $user)
    {
        try {
            $hasOrders = Order::where('user_id', $user->id)->exists();
            $hasTickets = Ticket::where('user_id', $user->id)->exists();
            
            if ($hasOrders || $hasTickets) {
                return back()->with('error', 'Cannot delete user with existing orders or tickets.');
            }

            Log::info('Admin deleted user', [
                'admin_id' => auth()->id(),
                'user_id' => $user->id,
                'username' => $user->username,
            ]);

            $user->delete();

            return redirect()->route('admin.users.index')
                ->with('success', 'User deleted successfully.');

        } catch (\Exception $e) {
            return back()->with('error', 'Failed to delete user. Please try again.');
        }
    }

    public function toggleStatus(User $user)
    {
        try {
            $oldStatus = $user->status;
            $newStatus = $oldStatus === 'active' ? 'inactive' : 'active';

            $user->update(['status' => $newStatus]);

            Log::info('Admin toggled user status', [
                'admin_id' => auth()->id(),
                'user_id' => $user->id,
                'username' => $user->username,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            $statusText = $newStatus === 'active' ? 'activated' : 'deactivated';
            return back()->with('success', "User {$statusText} successfully.");

        } catch (\Exception $e) {
            return back()->with('error', 'Failed to toggle user status. Please try again.');
        }
    }

    public function updateBalance(Request $request, User $user)
    {
        $validated = $request->validate([
            'action' => 'required|in:add,subtract,set',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            $oldBalance = $user->balance;
            $newBalance = $oldBalance;

            switch ($validated['action']) {
                case 'add':
                    $newBalance = $oldBalance + $validated['amount'];
                    break;
                case 'subtract':
                    if ($validated['amount'] > $oldBalance) {
                        return back()->with('error', 'Insufficient balance to subtract.');
                    }
                    $newBalance = $oldBalance - $validated['amount'];
                    break;
                case 'set':
                    $newBalance = $validated['amount'];
                    break;
            }

            $user->update(['balance' => $newBalance]);

            Log::info('Admin updated user balance', [
                'admin_id' => auth()->id(),
                'user_id' => $user->id,
                'username' => $user->username,
                'action' => $validated['action'],
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'amount' => $validated['amount'],
                'reason' => $validated['reason'],
            ]);

            DB::commit();

            return back()->with('success', 'User balance updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update user balance. Please try again.');
        }
    }

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'action' => 'required|in:activate,deactivate,suspend,delete,update_role,update_country',
            'role_id' => 'required_if:action,update_role|exists:roles,id',
            'country' => 'required_if:action,update_country|string|max:100',
        ]);

        try {
            DB::beginTransaction();

            $users = User::whereIn('id', $validated['user_ids'])->get();
            $updatedCount = 0;
            $errors = [];

            foreach ($users as $user) {
                try {
                    switch ($validated['action']) {
                        case 'activate':
                            $user->update(['status' => 'active']);
                            $updatedCount++;
                            break;

                        case 'deactivate':
                            $user->update(['status' => 'inactive']);
                            $updatedCount++;
                            break;

                        case 'suspend':
                            $user->update(['status' => 'suspended']);
                            $updatedCount++;
                            break;

                        case 'delete':
                            $hasOrders = Order::where('user_id', $user->id)->exists();
                            $hasTickets = Ticket::where('user_id', $user->id)->exists();
                            
                            if ($hasOrders || $hasTickets) {
                                $errors[] = "User '{$user->username}' cannot be deleted (has orders/tickets)";
                            } else {
                                $user->delete();
                                $updatedCount++;
                            }
                            break;

                        case 'update_role':
                            $user->syncRoles([$validated['role_id']]);
                            $updatedCount++;
                            break;

                        case 'update_country':
                            $user->update(['country' => $validated['country']]);
                            $updatedCount++;
                            break;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Failed to update user '{$user->username}'";
                }
            }

            Log::info('Admin performed bulk user update', [
                'admin_id' => auth()->id(),
                'action' => $validated['action'],
                'updated_count' => $updatedCount,
                'errors' => $errors,
            ]);

            DB::commit();

            $message = "Successfully updated {$updatedCount} users.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to perform bulk update. Please try again.');
        }
    }

    public function exportUsers(Request $request)
    {
        $query = User::with(['roles']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('role')) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        $users = $query->orderBy('username')->get();

        $filename = 'users_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($users) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'User ID', 'Username', 'Email', 'First Name', 'Last Name', 'Phone',
                'Country', 'Status', 'Balance', 'Roles', 'Email Verified', 'Created At'
            ]);

            foreach ($users as $user) {
                fputcsv($file, [
                    $user->id,
                    $user->username,
                    $user->email,
                    $user->first_name,
                    $user->last_name,
                    $user->phone,
                    $user->country,
                    $user->status,
                    $user->balance,
                    $user->roles->pluck('name')->implode(', '),
                    $user->email_verified_at ? 'Yes' : 'No',
                    $user->created_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function userAnalytics(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        $usersByRole = $this->getUsersByRole();
        $usersByStatus = $this->getUsersByStatus();
        $usersByCountry = $this->getUsersByCountry();
        $topUsersByOrders = $this->getTopUsersByOrders($dateFrom, $dateTo);
        $topUsersByBalance = $this->getTopUsersByBalance();
        $dailyRegistrations = $this->getDailyUserRegistrations($dateFrom, $dateTo);
        $userGrowth = $this->getUserGrowth($dateFrom, $dateTo);

        return view('admin.users.analytics', compact(
            'usersByRole',
            'usersByStatus',
            'usersByCountry',
            'topUsersByOrders',
            'topUsersByBalance',
            'dailyRegistrations',
            'userGrowth',
            'dateFrom',
            'dateTo'
        ));
    }

    private function getUsersByRole()
    {
        return Role::withCount('users')->orderBy('users_count', 'desc')->get();
    }

    private function getUsersByStatus()
    {
        return User::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();
    }

    private function getUsersByCountry()
    {
        return User::selectRaw('country, COUNT(*) as count')
            ->groupBy('country')
            ->orderBy('count', 'desc')
            ->take(10)
            ->get();
    }

    private function getTopUsersByOrders($dateFrom, $dateTo)
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

    private function getTopUsersByBalance()
    {
        return User::orderBy('balance', 'desc')->take(10)->get();
    }

    private function getDailyUserRegistrations($dateFrom, $dateTo)
    {
        return User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    private function getUserGrowth($dateFrom, $dateTo)
    {
        $startDate = \Carbon\Carbon::parse($dateFrom);
        $endDate = \Carbon\Carbon::parse($dateTo);
        
        $startCount = User::where('created_at', '<=', $startDate)->count();
        $endCount = User::where('created_at', '<=', $endDate)->count();
        
        $growth = $startCount > 0 ? (($endCount - $startCount) / $startCount) * 100 : 0;
        
        return [
            'start_count' => $startCount,
            'end_count' => $endCount,
            'growth_percentage' => round($growth, 2),
        ];
    }

    private function getCountriesList()
    {
        return [
            'US' => 'United States', 'CA' => 'Canada', 'GB' => 'United Kingdom',
            'DE' => 'Germany', 'FR' => 'France', 'IT' => 'Italy', 'ES' => 'Spain',
            'AU' => 'Australia', 'JP' => 'Japan', 'KR' => 'South Korea', 'CN' => 'China',
            'IN' => 'India', 'BR' => 'Brazil', 'MX' => 'Mexico', 'RU' => 'Russia',
        ];
    }
}