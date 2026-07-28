<?php

namespace App\Modules\Contact\Http\Resources;

use App\Modules\Contact\DTOs\ImportResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;


// ─── Import Result Resource ───────────────────────────────────────────────────
class ImportResultResource
{
    public static function toArray(ImportResultDTO $result): array
    {
        return [
            'summary' => [
                'total'    => $result->total(),
                'imported' => $result->imported,
                'skipped'  => $result->skipped,
                'failed'   => $result->failed,
            ],
            'errors' => array_slice($result->errors, 0, 20), // cap at 20
        ];
    }
}
