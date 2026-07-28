<?php

namespace App\Modules\Wallet\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wallet\DTOs\CreateOrderDTO;
use App\Modules\Wallet\DTOs\TransactionFilterDTO;
use App\Modules\Wallet\DTOs\VerifyPaymentDTO;
use App\Modules\Wallet\DTOs\WalletSettingsDTO;
use App\Modules\Wallet\Http\Requests\CreateOrderRequest;
use App\Modules\Wallet\Http\Requests\TransactionFilterRequest;
use App\Modules\Wallet\Http\Requests\VerifyPaymentRequest;
use App\Modules\Wallet\Http\Requests\WalletSettingsRequest;
use App\Modules\Wallet\Http\Resources\OrderResultResource;
use App\Modules\Wallet\Http\Resources\PackageResource;
use App\Modules\Wallet\Http\Resources\TransactionResource;
use App\Modules\Wallet\Http\Resources\VerifyResultResource;
use App\Modules\Wallet\Http\Resources\WalletResource;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// ─── Payment Controller ───────────────────────────────────────────────────────
class PaymentController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    // POST /wallet/create-order
    public function createOrder(CreateOrderRequest $request): JsonResponse
    {
        $result = $this->walletService->createOrder(
            companyId: auth()->user()->company_id,
            userId:    auth()->id(),
            dto:       CreateOrderDTO::fromRequest($request->validated()),
        );

        return response()->json(OrderResultResource::toArray($result));
    }

    // POST /wallet/verify-payment
    public function verifyPayment(VerifyPaymentRequest $request): JsonResponse
    {
        $result = $this->walletService->verifyPayment(
            companyId: auth()->user()->company_id,
            dto:       VerifyPaymentDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => "Payment successful! {$result->credited} messages credited.",
            'result'  => VerifyResultResource::toArray($result),
        ]);
    }

    // POST /razorpay/webhook (public — Razorpay server-to-server)
    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->header('X-Razorpay-Signature');
        $body      = $request->getContent();
        $secret    = config('services.razorpay.webhook_secret');

        // Verify webhook signature
        $expectedSig = hash_hmac('sha256', $body, $secret);
        if (!hash_equals($expectedSig, $signature ?? '')) {
            Log::warning('Razorpay webhook: invalid signature');
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = $request->input('event');
        Log::info("Razorpay webhook received: {$event}");

        // Handle payment.captured (backup to frontend verify)
        if ($event === 'payment.captured') {
            $payment = $request->input('payload.payment.entity');
            $orderId = $payment['order_id'] ?? null;

            if ($orderId) {
                // Find the order across all companies
                $order = \App\Models\PaymentOrder::where('razorpay_order_id', $orderId)
                    ->where('status', 'pending')
                    ->first();

                if ($order) {
                    $dto = new VerifyPaymentDTO(
                        razorpayOrderId:   $orderId,
                        razorpayPaymentId: $payment['id'],
                        razorpaySignature: '', // webhook doesn't send this; skip sig check
                    );

                    try {
                        $this->walletService->verifyPayment($order->company_id, $dto);
                        Log::info("Webhook: credited wallet for company {$order->company_id}");
                    } catch (\Exception $e) {
                        Log::error("Webhook: credit failed — {$e->getMessage()}");
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
