<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['user', 'order']);

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                  ->orWhere('payment_method', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('username', 'like', "%{$search}%")
                               ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('min_amount')) {
            $query->where('amount', '>=', $request->min_amount);
        }
        if ($request->filled('max_amount')) {
            $query->where('amount', '<=', $request->max_amount);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->get('per_page', 20);
        $payments = $query->paginate($perPage);

        // Get filter options
        $users = User::where('status', 'active')->orderBy('username')->get();
        $paymentMethods = ['stripe', 'paypal', 'bank_transfer', 'crypto', 'manual'];

        return view('admin.payments.index', compact('payments', 'users', 'paymentMethods'));
    }

    public function create()
    {
        $users = User::where('status', 'active')->orderBy('username')->get();
        $orders = Order::where('status', 'pending')->orderBy('created_at', 'desc')->get();
        $paymentMethods = ['stripe', 'paypal', 'bank_transfer', 'crypto', 'manual'];

        return view('admin.payments.create', compact('users', 'orders', 'paymentMethods'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'order_id' => 'nullable|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:stripe,paypal,bank_transfer,crypto,manual',
            'status' => 'required|in:pending,completed,failed,cancelled,refunded',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $validated['transaction_id'] = $this->generateTransactionId();
            $payment = Payment::create($validated);

            if ($payment->status === 'completed') {
                $this->handlePaymentStatusChange($payment, 'pending', 0, $payment->user_id, $payment->user_id);
            }

            Log::info('Admin created payment', [
                'admin_id' => auth()->id(),
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
            ]);

            DB::commit();

            return redirect()->route('admin.payments.show', $payment)
                ->with('success', 'Payment created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to create payment. Please try again.');
        }
    }

    public function show(Payment $payment)
    {
        $payment->load(['user', 'order.service', 'order.provider']);

        $relatedPayments = Payment::where('user_id', $payment->user_id)
            ->where('id', '!=', $payment->id)
            ->with(['order.service'])
            ->latest()
            ->take(10)
            ->get();

        return view('admin.payments.show', compact('payment', 'relatedPayments'));
    }

    public function edit(Payment $payment)
    {
        $users = User::where('status', 'active')->orderBy('username')->get();
        $orders = Order::orderBy('created_at', 'desc')->get();
        $paymentMethods = ['stripe', 'paypal', 'bank_transfer', 'crypto', 'manual'];

        return view('admin.payments.edit', compact('payment', 'users', 'orders', 'paymentMethods'));
    }

    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'order_id' => 'nullable|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:stripe,paypal,bank_transfer,crypto,manual',
            'status' => 'required|in:pending,completed,failed,cancelled,refunded',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $oldStatus = $payment->status;
            $oldAmount = $payment->amount;
            $oldUserId = $payment->user_id;

            $payment->update($validated);

            if ($oldStatus !== $payment->status || $oldAmount !== $payment->amount || $oldUserId !== $payment->user_id) {
                $this->handlePaymentStatusChange($payment, $oldStatus, $oldAmount, $oldUserId, $payment->user_id);
            }

            Log::info('Admin updated payment', [
                'admin_id' => auth()->id(),
                'payment_id' => $payment->id,
                'old_status' => $oldStatus,
                'new_status' => $payment->status,
            ]);

            DB::commit();

            return redirect()->route('admin.payments.show', $payment)
                ->with('success', 'Payment updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to update payment. Please try again.');
        }
    }

    public function destroy(Payment $payment)
    {
        try {
            if ($payment->status === 'completed') {
                return back()->with('error', 'Cannot delete completed payments.');
            }

            Log::info('Admin deleted payment', [
                'admin_id' => auth()->id(),
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
            ]);

            $payment->delete();

            return redirect()->route('admin.payments.index')
                ->with('success', 'Payment deleted successfully.');

        } catch (\Exception $e) {
            return back()->with('error', 'Failed to delete payment. Please try again.');
        }
    }

    public function updateStatus(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,completed,failed,cancelled,refunded',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $oldStatus = $payment->status;
            $oldAmount = $payment->amount;
            $oldUserId = $payment->user_id;

            $payment->update([
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? $payment->notes,
            ]);

            $this->handlePaymentStatusChange($payment, $oldStatus, $oldAmount, $oldUserId, $payment->user_id);

            Log::info('Admin updated payment status', [
                'admin_id' => auth()->id(),
                'payment_id' => $payment->id,
                'old_status' => $oldStatus,
                'new_status' => $payment->status,
            ]);

            DB::commit();

            return back()->with('success', 'Payment status updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update payment status. Please try again.');
        }
    }

    public function processPayment(Payment $payment)
    {
        try {
            if ($payment->status !== 'pending') {
                return back()->with('error', 'Only pending payments can be processed.');
            }

            DB::beginTransaction();

            $oldStatus = $payment->status;
            $oldAmount = $payment->amount;
            $oldUserId = $payment->user_id;

            $payment->update(['status' => 'completed']);
            $this->handlePaymentStatusChange($payment, $oldStatus, $oldAmount, $oldUserId, $payment->user_id);

            Log::info('Admin processed payment', [
                'admin_id' => auth()->id(),
                'payment_id' => $payment->id,
            ]);

            DB::commit();

            return back()->with('success', 'Payment processed successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to process payment. Please try again.');
        }
    }

    public function refundPayment(Payment $payment)
    {
        try {
            if ($payment->status !== 'completed') {
                return back()->with('error', 'Only completed payments can be refunded.');
            }

            DB::beginTransaction();

            $oldStatus = $payment->status;
            $oldAmount = $payment->amount;
            $oldUserId = $payment->user_id;

            $payment->update(['status' => 'refunded']);
            $this->handlePaymentStatusChange($payment, $oldStatus, $oldAmount, $oldUserId, $payment->user_id);

            Log::info('Admin refunded payment', [
                'admin_id' => auth()->id(),
                'payment_id' => $payment->id,
            ]);

            DB::commit();

            return back()->with('success', 'Payment refunded successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to refund payment. Please try again.');
        }
    }

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'payment_ids' => 'required|array',
            'payment_ids.*' => 'exists:payments,id',
            'action' => 'required|in:complete,fail,cancel,refund,delete',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            DB::beginTransaction();

            $payments = Payment::whereIn('id', $validated['payment_ids'])->get();
            $updatedCount = 0;
            $errors = [];

            foreach ($payments as $payment) {
                try {
                    switch ($validated['action']) {
                        case 'complete':
                            if ($payment->status === 'pending') {
                                $this->handlePaymentStatusChange($payment, $payment->status, $payment->amount, $payment->user_id, $payment->user_id);
                                $payment->update(['status' => 'completed']);
                                $updatedCount++;
                            } else {
                                $errors[] = "Payment {$payment->transaction_id} cannot be completed";
                            }
                            break;

                        case 'fail':
                            if (in_array($payment->status, ['pending', 'processing'])) {
                                $payment->update(['status' => 'failed']);
                                $updatedCount++;
                            } else {
                                $errors[] = "Payment {$payment->transaction_id} cannot be failed";
                            }
                            break;

                        case 'cancel':
                            if (in_array($payment->status, ['pending', 'processing'])) {
                                $payment->update(['status' => 'cancelled']);
                                $updatedCount++;
                            } else {
                                $errors[] = "Payment {$payment->transaction_id} cannot be cancelled";
                            }
                            break;

                        case 'refund':
                            if ($payment->status === 'completed') {
                                $this->handlePaymentStatusChange($payment, $payment->status, $payment->amount, $payment->user_id, $payment->user_id);
                                $payment->update(['status' => 'refunded']);
                                $updatedCount++;
                            } else {
                                $errors[] = "Payment {$payment->transaction_id} cannot be refunded";
                            }
                            break;

                        case 'delete':
                            if ($payment->status === 'completed') {
                                $errors[] = "Payment {$payment->transaction_id} cannot be deleted";
                            } else {
                                $payment->delete();
                                $updatedCount++;
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Failed to update payment {$payment->transaction_id}";
                }
            }

            Log::info('Admin performed bulk payment update', [
                'admin_id' => auth()->id(),
                'action' => $validated['action'],
                'updated_count' => $updatedCount,
                'errors' => $errors,
            ]);

            DB::commit();

            $message = "Successfully updated {$updatedCount} payments.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', $errors);
            }

            return back()->with('success', $message);

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to perform bulk update. Please try again.');
        }
    }

    public function exportPayments(Request $request)
    {
        $query = Payment::with(['user', 'order']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        $payments = $query->orderBy('created_at', 'desc')->get();

        $filename = 'payments_' . date('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($payments) {
            $file = fopen('php://output', 'w');

            fputcsv($file, [
                'Payment ID', 'Transaction ID', 'User', 'Email', 'Order ID', 'Amount',
                'Payment Method', 'Status', 'Reference', 'Notes', 'Created At'
            ]);

            foreach ($payments as $payment) {
                fputcsv($file, [
                    $payment->id,
                    $payment->transaction_id,
                    $payment->user->username ?? 'N/A',
                    $payment->user->email ?? 'N/A',
                    $payment->order_id ?? 'N/A',
                    $payment->amount,
                    $payment->payment_method,
                    $payment->status,
                    $payment->reference ?? '',
                    $payment->notes ?? '',
                    $payment->created_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function paymentAnalytics(Request $request)
    {
        $dateFrom = $request->get('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->get('date_to', now()->format('Y-m-d'));

        $paymentsByStatus = $this->getPaymentsByStatus();
        $paymentsByMethod = $this->getPaymentsByMethod();
        $dailyPaymentCounts = $this->getDailyPaymentCounts($dateFrom, $dateTo);
        $dailyPaymentAmounts = $this->getDailyPaymentAmounts($dateFrom, $dateTo);
        $topPayingUsers = $this->getTopPayingUsers($dateFrom, $dateTo);
        $paymentSuccessRate = $this->getPaymentSuccessRate($dateFrom, $dateTo);

        return view('admin.payments.analytics', compact(
            'paymentsByStatus',
            'paymentsByMethod',
            'dailyPaymentCounts',
            'dailyPaymentAmounts',
            'topPayingUsers',
            'paymentSuccessRate',
            'dateFrom',
            'dateTo'
        ));
    }

    private function handlePaymentStatusChange($payment, $oldStatus, $oldAmount, $oldUserId, $newUserId)
    {
        if ($payment->status === 'completed' && $oldStatus !== 'completed') {
            $user = User::find($newUserId);
            if ($user) {
                $user->increment('balance', $payment->amount);
                
                if ($payment->order_id) {
                    Order::where('id', $payment->order_id)->update(['status' => 'processing']);
                }
            }
        }

        if ($oldStatus === 'completed' && $payment->status !== 'completed') {
            $user = User::find($oldUserId);
            if ($user) {
                $user->decrement('balance', $oldAmount);
                
                if ($payment->order_id) {
                    Order::where('id', $payment->order_id)->update(['status' => 'pending']);
                }
            }
        }
    }

    private function generateTransactionId()
    {
        do {
            $transactionId = 'TXN-' . strtoupper(substr(md5(uniqid()), 0, 6));
        } while (Payment::where('transaction_id', $transactionId)->exists());

        return $transactionId;
    }

    private function getPaymentsByStatus()
    {
        return Payment::selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderBy('count', 'desc')
            ->get();
    }

    private function getPaymentsByMethod()
    {
        return Payment::selectRaw('payment_method, COUNT(*) as count')
            ->groupBy('payment_method')
            ->orderBy('count', 'desc')
            ->get();
    }

    private function getDailyPaymentCounts($dateFrom, $dateTo)
    {
        return Payment::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    private function getDailyPaymentAmounts($dateFrom, $dateTo)
    {
        return Payment::selectRaw('DATE(created_at) as date, SUM(amount) as total_amount')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    private function getTopPayingUsers($dateFrom, $dateTo)
    {
        return User::withSum(['payments' => function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', 'completed')
                      ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            }], 'amount')
            ->orderBy('payments_sum_amount', 'desc')
            ->take(10)
            ->get();
    }

    private function getPaymentSuccessRate($dateFrom, $dateTo)
    {
        $totalPayments = Payment::whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])->count();
        $completedPayments = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
            ->count();

        $successRate = $totalPayments > 0 ? ($completedPayments / $totalPayments) * 100 : 0;

        return [
            'total_payments' => $totalPayments,
            'completed_payments' => $completedPayments,
            'success_rate' => round($successRate, 2),
        ];
    }
}