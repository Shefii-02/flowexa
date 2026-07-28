<?php

namespace App\Modules\Contact\Http\Resources;

use App\Modules\Contact\DTOs\ImportResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

// ─── Contact Collection ───────────────────────────────────────────────────────
class ContactCollection extends ResourceCollection
{
    public $collects = ContactResource::class;

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
