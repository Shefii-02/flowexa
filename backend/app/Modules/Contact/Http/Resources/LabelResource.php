<?php

namespace App\Modules\Contact\Http\Resources;

use App\Modules\Contact\DTOs\ImportResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

// ─── Label Resource ───────────────────────────────────────────────────────────
class LabelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'color'          => $this->color,
            'contacts_count' => $this->contacts_count ?? 0,
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
