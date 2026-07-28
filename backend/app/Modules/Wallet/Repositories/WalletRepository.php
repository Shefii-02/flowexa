<?php

namespace App\Modules\Wallet\Repositories;

use App\Models\PaymentOrder;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Wallet\DTOs\WalletSettingsDTO;
use App\Modules\Wallet\Repositories\Interfaces\WalletRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class WalletRepository implements WalletRepositoryInterface
{
    public function findByCompany(int $companyId): ?Wallet
    {
        return Wallet::where('company_id', $companyId)->first();
    }

    // ─── Credit (atomic) ──────────────────────────────────────────────────────
    public function credit(Wallet $wallet, int $amount, string $description, ?string $refId, ?string $refType): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $description, $refId, $refType) {
            // Lock row to prevent race conditions
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();
            $before = $wallet->balance;

            $wallet->increment('balance', $amount);
            $wallet->increment('total_purchased', $amount);

            return WalletTransaction::create([
                'company_id'     => $wallet->company_id,
                'user_id'        => auth()->id(),
                'type'           => 'credit',
                'amount'         => $amount,
                'balance_before' => $before,
                'balance_after'  => $before + $amount,
                'description'    => $description,
                'reference_id'   => $refId,
                'reference_type' => $refType,
            ]);
        });
    }

    // ─── Debit (atomic) ───────────────────────────────────────────────────────
    public function debit(Wallet $wallet, int $amount, string $description, ?string $refId, ?string $refType): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $description, $refId, $refType) {
            $wallet = Wallet::where('id', $wallet->id)->lockForUpdate()->first();

            if ($wallet->balance < $amount) {
                throw new \RuntimeException('Insufficient wallet balance.');
            }

            $before = $wallet->balance;
            $wallet->decrement('balance', $amount);
            $wallet->increment('total_used', $amount);

            return WalletTransaction::create([
                'company_id'     => $wallet->company_id,
                'user_id'        => auth()->id(),
                'type'           => 'debit',
                'amount'         => $amount,
                'balance_before' => $before,
                'balance_after'  => $before - $amount,
                'description'    => $description,
                'reference_id'   => $refId,
                'reference_type' => $refType,
            ]);
        });
    }

    // ─── Update settings ──────────────────────────────────────────────────────
    public function updateSettings(Wallet $wallet, WalletSettingsDTO $dto): Wallet
    {
        $data = array_filter([
            'low_balance_alert'       => $dto->lowBalanceAlert,
            'auto_recharge'           => $dto->autoRecharge,
            'auto_recharge_amount'    => $dto->autoRechargeAmount,
            'auto_recharge_threshold' => $dto->autoRechargeThreshold,
        ], fn($v) => !is_null($v));

        $wallet->update($data);
        return $wallet->fresh();
    }

    // ─── Transaction history ──────────────────────────────────────────────────
    public function transactions(int $companyId, ?string $type, int $perPage, int $page): LengthAwarePaginator
    {
        return WalletTransaction::where('company_id', $companyId)
            ->when($type, fn($q) => $q->where('type', $type))
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }

    // ─── Create Razorpay order record ─────────────────────────────────────────
    public function createPaymentOrder(int $companyId, int $userId, string $razorpayOrderId, int $amount, int $messagesCredit): PaymentOrder
    {
        return PaymentOrder::create([
            'company_id'        => $companyId,
            'user_id'           => $userId,
            'razorpay_order_id' => $razorpayOrderId,
            'amount'            => $amount,
            'messages_credit'   => $messagesCredit,
            'status'            => 'pending',
        ]);
    }

    // ─── Find pending order ───────────────────────────────────────────────────
    public function findPendingOrder(string $razorpayOrderId, int $companyId): ?PaymentOrder
    {
        return PaymentOrder::where('razorpay_order_id', $razorpayOrderId)
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->first();
    }

    // ─── Mark order paid ──────────────────────────────────────────────────────
    public function markOrderPaid(PaymentOrder $order, string $paymentId, string $signature): PaymentOrder
    {
        $order->update([
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature'  => $signature,
            'status'              => 'paid',
        ]);
        return $order->fresh();
    }

    // ─── Mark order failed ────────────────────────────────────────────────────
    public function markOrderFailed(PaymentOrder $order): PaymentOrder
    {
        $order->update(['status' => 'failed']);
        return $order->fresh();
    }
}
