<?php

namespace App\Modules\Wallet\Repositories\Interfaces;

use App\Models\PaymentOrder;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\Wallet\DTOs\WalletSettingsDTO;
use Illuminate\Pagination\LengthAwarePaginator;

interface WalletRepositoryInterface
{
    public function findByCompany(int $companyId): ?Wallet;

    public function credit(Wallet $wallet, int $amount, string $description, ?string $refId, ?string $refType): WalletTransaction;

    public function debit(Wallet $wallet, int $amount, string $description, ?string $refId, ?string $refType): WalletTransaction;

    public function updateSettings(Wallet $wallet, WalletSettingsDTO $dto): Wallet;

    public function transactions(int $companyId, ?string $type, int $perPage, int $page): LengthAwarePaginator;

    public function createPaymentOrder(int $companyId, int $userId, string $razorpayOrderId, int $amount, int $messagesCredit): PaymentOrder;

    public function findPendingOrder(string $razorpayOrderId, int $companyId): ?PaymentOrder;

    public function markOrderPaid(PaymentOrder $order, string $paymentId, string $signature): PaymentOrder;

    public function markOrderFailed(PaymentOrder $order): PaymentOrder;
}
