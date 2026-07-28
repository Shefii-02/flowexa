<?php

namespace App\Modules\Wallet\Services;

use App\Models\Wallet;
use App\Modules\Wallet\DTOs\CreateOrderDTO;
use App\Modules\Wallet\DTOs\OrderResultDTO;
use App\Modules\Wallet\DTOs\PackageDTO;
use App\Modules\Wallet\DTOs\TransactionFilterDTO;
use App\Modules\Wallet\DTOs\VerifyPaymentDTO;
use App\Modules\Wallet\DTOs\VerifyResultDTO;
use App\Modules\Wallet\DTOs\WalletSettingsDTO;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Repositories\Interfaces\WalletRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class WalletService
{
    // ─── Available packages ───────────────────────────────────────────────────
    private const PACKAGES = [
        1000  => ['price' => 199,  'label' => '1,000 messages',  'popular' => false],
        5000  => ['price' => 799,  'label' => '5,000 messages',  'popular' => true],
        10000 => ['price' => 1299, 'label' => '10,000 messages', 'popular' => false],
        25000 => ['price' => 2799, 'label' => '25,000 messages', 'popular' => false],
        50000 => ['price' => 4999, 'label' => '50,000 messages', 'popular' => false],
    ];

    public function __construct(
        private readonly WalletRepositoryInterface $walletRepository,
    ) {}

    // ─── Get wallet ───────────────────────────────────────────────────────────
    public function getWallet(int $companyId): Wallet
    {
        $wallet = $this->walletRepository->findByCompany($companyId);
        if (!$wallet) throw WalletException::notFound();
        return $wallet;
    }

    // ─── Transaction history ──────────────────────────────────────────────────
    public function transactions(int $companyId, TransactionFilterDTO $dto): LengthAwarePaginator
    {
        return $this->walletRepository->transactions(
            $companyId, $dto->type, $dto->perPage, $dto->page
        );
    }

    // ─── Available packages ───────────────────────────────────────────────────
    public function packages(): array
    {
        return collect(self::PACKAGES)
            ->map(fn($pkg, $messages) => new PackageDTO(
                messages: $messages,
                priceInr: $pkg['price'],
                label:    $pkg['label'],
                popular:  $pkg['popular'],
            ))
            ->values()
            ->all();
    }

    // ─── Update settings ──────────────────────────────────────────────────────
    public function updateSettings(int $companyId, WalletSettingsDTO $dto): Wallet
    {
        $wallet = $this->getWallet($companyId);
        return $this->walletRepository->updateSettings($wallet, $dto);
    }

    // ─── Create Razorpay order ────────────────────────────────────────────────
    public function createOrder(int $companyId, int $userId, CreateOrderDTO $dto): OrderResultDTO
    {
        $package = self::PACKAGES[$dto->messages] ?? null;

        if (!$package) {
            throw WalletException::invalidPackage($dto->messages);
        }

        try {
            $api = new \Razorpay\Api\Api(
                config('services.razorpay.key'),
                config('services.razorpay.secret')
            );

            $order = $api->order->create([
                'receipt'  => 'WAAPI_' . uniqid(),
                'amount'   => $package['price'] * 100, // in paise
                'currency' => 'INR',
                'notes'    => [
                    'company_id'      => $companyId,
                    'messages_credit' => $dto->messages,
                ],
            ]);

            // Persist order record
            $this->walletRepository->createPaymentOrder(
                companyId:       $companyId,
                userId:          $userId,
                razorpayOrderId: $order->id,
                amount:          $package['price'],
                messagesCredit:  $dto->messages,
            );

            return new OrderResultDTO(
                orderId:     $order->id,
                amountPaise: $package['price'] * 100,
                currency:    'INR',
                razorpayKey: config('services.razorpay.key'),
                messages:    $dto->messages,
                priceInr:    $package['price'],
            );

        } catch (\Exception $e) {
            Log::error('Razorpay order creation failed', [
                'error'      => $e->getMessage(),
                'company_id' => $companyId,
            ]);
            throw WalletException::orderCreationFailed();
        }
    }

    // ─── Verify payment + credit wallet ───────────────────────────────────────
    public function verifyPayment(int $companyId, VerifyPaymentDTO $dto): VerifyResultDTO
    {
        $order = $this->walletRepository->findPendingOrder($dto->razorpayOrderId, $companyId);

        if (!$order) {
            throw WalletException::orderNotFound();
        }

        // Verify Razorpay signature
        $expectedSig = hash_hmac(
            'sha256',
            $dto->razorpayOrderId . '|' . $dto->razorpayPaymentId,
            config('services.razorpay.secret')
        );

        if (!hash_equals($expectedSig, $dto->razorpaySignature)) {
            $this->walletRepository->markOrderFailed($order);
            throw WalletException::signatureInvalid();
        }

        // Mark order paid
        $this->walletRepository->markOrderPaid($order, $dto->razorpayPaymentId, $dto->razorpaySignature);

        // Credit wallet
        $wallet = $this->getWallet($companyId);
        $tx     = $this->walletRepository->credit(
            wallet:      $wallet,
            amount:      $order->messages_credit,
            description: "Recharge — {$order->messages_credit} messages via Razorpay",
            refId:       $dto->razorpayPaymentId,
            refType:     'recharge',
        );

        return new VerifyResultDTO(
            success:       true,
            credited:      $order->messages_credit,
            newBalance:    $wallet->fresh()->balance,
            transactionId: (string) $tx->id,
        );
    }

    // ─── Manual debit (used by other modules: OTP, Campaign) ─────────────────
    public function debit(int $companyId, int $amount, string $description, ?string $refId = null, ?string $refType = null): void
    {
        $wallet = $this->getWallet($companyId);

        if ($wallet->balance < $amount) {
            throw WalletException::insufficientBalance($wallet->balance, $amount);
        }

        $this->walletRepository->debit($wallet, $amount, $description, $refId, $refType);

        // Check if low balance alert should fire
        $fresh = $wallet->fresh();
        if ($fresh->balance <= $fresh->low_balance_alert) {
            Log::info("Low balance alert: company {$companyId} balance {$fresh->balance}");
            // Dispatch notification event here in future
        }
    }
}
