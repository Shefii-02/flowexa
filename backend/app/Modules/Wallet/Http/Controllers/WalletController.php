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

// ─── Wallet Controller ────────────────────────────────────────────────────────
class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    // GET /wallet
    public function index(): JsonResponse
    {
        $wallet = $this->walletService->getWallet(auth()->user()->company_id);

        return response()->json([
            'wallet' => new WalletResource($wallet),
        ]);
    }

    // GET /wallet/transactions
    public function transactions(TransactionFilterRequest $request): JsonResponse
    {
        $paginator = $this->walletService->transactions(
            companyId: auth()->user()->company_id,
            dto:       TransactionFilterDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'data'         => TransactionResource::collection($paginator->items()),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
        ]);
    }

    // GET /wallet/packages
    public function packages(): JsonResponse
    {
        return response()->json([
            'packages' => PackageResource::collection($this->walletService->packages()),
        ]);
    }

    // PUT /wallet/settings
    public function updateSettings(WalletSettingsRequest $request): JsonResponse
    {
        $wallet = $this->walletService->updateSettings(
            companyId: auth()->user()->company_id,
            dto:       WalletSettingsDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Wallet settings updated.',
            'wallet'  => new WalletResource($wallet),
        ]);
    }
}
