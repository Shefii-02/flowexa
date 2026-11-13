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

        // Optional coupon — validated up front so a bad code is a clear 422, not a payment-gateway failure.
        $q = $this->quote($companyId, $dto->messages, $dto->couponCode);
        $final = $q['final'];
        $couponFields = ['purpose' => 'topup', 'subtotal' => $package['price'], 'discount' => $q['discount'],
                         'coupon_id' => $q['coupon']?->id, 'coupon_code' => $q['coupon']?->code];

        try {
            // A coupon that covers the whole price credits the wallet with no payment step.
            if ($final <= 0) {
                $order = \App\Models\PaymentOrder::create(['company_id' => $companyId, 'user_id' => $userId, 'razorpay_order_id' => 'FREE_' . uniqid(),
                    'amount' => 0, 'messages_credit' => $dto->messages, 'status' => 'paid', 'gateway' => 'free', 'paid_at' => now()] + $couponFields);
                $wallet = $this->getWallet($companyId);
                $this->walletRepository->credit(wallet: $wallet, amount: $dto->messages, description: "Recharge — {$dto->messages} messages (coupon {$q['coupon']->code})", refId: $order->razorpay_order_id, refType: 'recharge');
                app(\App\Support\CouponService::class)->redeem($order);
                return new OrderResultDTO('', 0, 'INR', '', $dto->messages, $package['price'], $q['discount'], $q['coupon']->code, true, (int) $wallet->fresh()->balance);
            }

            $api = new \Razorpay\Api\Api(
                config('services.razorpay.key'),
                config('services.razorpay.secret')
            );

            $order = $api->order->create([
                'receipt'  => 'WAAPI_' . uniqid(),
                'amount'   => (int) round($final * 100), // in paise
                'currency' => 'INR',
                'notes'    => [
                    'company_id'      => $companyId,
                    'messages_credit' => $dto->messages,
                    'coupon'          => $q['coupon']?->code,
                ],
            ]);

            // Persist order record
            $row = $this->walletRepository->createPaymentOrder(
                companyId:       $companyId,
                userId:          $userId,
                razorpayOrderId: $order->id,
                amount:          (int) ceil($final),
                messagesCredit:  $dto->messages,
            );
            $row->update(['amount' => $final, 'gateway' => 'razorpay'] + $couponFields);

            return new OrderResultDTO(
                orderId:     $order->id,
                amountPaise: (int) round($final * 100),
                currency:    'INR',
                razorpayKey: config('services.razorpay.key'),
                messages:    $dto->messages,
                priceInr:    (int) ceil($final),
                discount:    $q['discount'],
                couponCode:  $q['coupon']?->code,
            );

        } catch (\Exception $e) {
            Log::error('Razorpay order creation failed', [
                'error'      => $e->getMessage(),
                'company_id' => $companyId,
            ]);
            throw WalletException::orderCreationFailed();
        }
    }

    /** Price of a top-up package after an optional coupon. Invalid code → ValidationException (coupon_code). */
    public function quote(int $companyId, int $messages, ?string $couponCode): array
    {
        $package = self::PACKAGES[$messages] ?? null;
        if (!$package) { throw WalletException::invalidPackage($messages); }
        $price = (float) $package['price']; $coupon = null; $discount = 0.0;
        if (filled($couponCode)) {
            $r = app(\App\Support\CouponService::class)->evaluate($couponCode, $price, 'topup', $companyId);
            $coupon = $r['coupon']; $discount = $r['discount'];
        }
        return ['subtotal' => $price, 'discount' => $discount, 'final' => round($price - $discount, 2), 'coupon' => $coupon];
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
        $order = $this->walletRepository->markOrderPaid($order, $dto->razorpayPaymentId, $dto->razorpaySignature);
        $order->update(['paid_at' => now()]);
        app(\App\Support\CouponService::class)->redeem($order);

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

    // ─── Billing mode ────────────────────────────────────────────────────────
    // companies.wa_config: 'wallet'      → the company pays per message from its prepaid wallet.
    //                      'own_account' → messages go out on the company's own Meta WABA and Meta
    //                                      bills them directly; the wallet is never touched.
    public function usesWallet(\App\Models\Company $company): bool
    {
        return ($company->wa_config ?? 'wallet') === 'wallet';
    }

    /** Debit only for wallet-billed companies; own-account companies are a no-op. */
    public function chargeIfWallet(\App\Models\Company $company, int $amount, string $description, ?string $refId = null, ?string $refType = null): void
    {
        if ($this->usesWallet($company)) {
            $this->debit($company->id, $amount, $description, $refId, $refType);
        }
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
