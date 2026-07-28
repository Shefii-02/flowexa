<?php

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\DTOs\OrderResultDTO;
use App\Modules\Wallet\DTOs\PackageDTO;
use App\Modules\Wallet\DTOs\VerifyResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


// ─── Package Resource ─────────────────────────────────────────────────────────
class PackageResource
{
    public static function collection(array $packages): array
    {
        return array_map(fn(PackageDTO $pkg) => [
            'messages'  => $pkg->messages,
            'price_inr' => $pkg->priceInr,
            'label'     => $pkg->label,
            'popular'   => $pkg->popular,
        ], $packages);
    }
}
