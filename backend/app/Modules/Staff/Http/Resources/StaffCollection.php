<?php

namespace App\Modules\Staff\Http\Resources;

use App\Modules\Staff\DTOs\StaffPerformanceDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

// ─── Staff Collection ─────────────────────────────────────────────────────────
class StaffCollection extends ResourceCollection
{
    public $collects = StaffResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data'         => $this->collection,
            'total'        => $this->total(),
            'per_page'     => $this->perPage(),
            'current_page' => $this->currentPage(),
            'last_page'    => $this->lastPage(),
        ];
    }
}
