<?php

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\DTOs\AuthResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    /**
     * @var AuthResultDTO
     */
    public $resource;

    public function toArray(Request $request): array
    {
        /** @var AuthResultDTO $result */
        $result = $this->resource;

        return [
            'access_token' => $result->token->accessToken,
            'token_type'   => $result->token->tokenType,
            'expires_in'   => $result->token->expiresIn,
            'user'         => new UserResource($result->user),
        ];
    }
}
