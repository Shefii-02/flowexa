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

    // GET /wallet/billing — how this company pays for each channel, and what WA Chat has cost this month.
    //   WA Chat (open-wa): per delivered message from the wallet (or included in the plan, set by the superadmin).
    //   WA Cloud:          wallet, or the company's own Meta account (Meta bills it, the wallet is not touched).
    public function billing(): \Illuminate\Http\JsonResponse
    {
        $company = auth()->user()->company;
        $billing = app(\App\Modules\WaChat\Services\WaChatBilling::class);
        $from    = now()->startOfMonth();
        $rows    = \Illuminate\Support\Facades\DB::table('wa_chat_charges')->where('company_id', $company->id)->where('created_at', '>=', $from)
            ->selectRaw('source, status, count(*) as messages, sum(credits) as credits')->groupBy('source', 'status')->get();
        $bySource = [];
        foreach ($rows as $r) {
            $b = &$bySource[$r->source];
            $b ??= ['source' => $r->source, 'messages' => 0, 'credits' => 0, 'unbilled' => 0];
            if ($r->status === 'charged') { $b['messages'] += (int) $r->messages; $b['credits'] += (int) $r->credits; } else { $b['unbilled'] += (int) $r->messages; }
            unset($b);
        }
        return response()->json(['data' => [
            'wa_chat' => [
                'mode'                => $company->wa_chat_billing ?? 'wallet',
                'credits_per_message' => $billing->creditsPerMessage($company),
                'this_month'          => array_values($bySource),
                'credits_this_month'  => (int) collect($bySource)->sum('credits'),
                'unbilled_this_month' => (int) collect($bySource)->sum('unbilled'),
            ],
            'wa_cloud' => ['mode' => $company->wa_config ?? 'wallet'],   // wallet | own_account
        ]]);
    }
}
