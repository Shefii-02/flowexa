<?php

namespace App\Modules\Campaign\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;


class CampaignCollection extends ResourceCollection
{
    public $collects = CampaignResource::class;

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
