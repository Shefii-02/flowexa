<?php

namespace App\Modules\Flow\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class FlowException extends Exception
{
    public function __construct(
        string               $message,
        private readonly int $statusCode  = 400,
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Flow node not found.', 404, 'node_not_found');
    }

    public static function parentNotFound(): self
    {
        return new self('Parent node not found or does not belong to this company.', 422, 'parent_not_found');
    }

    public static function circularReference(): self
    {
        return new self('Cannot set a node as its own parent or descendant.', 422, 'circular_reference');
    }

    public static function replyIdDuplicate(string $replyId): self
    {
        return new self("Reply ID '{$replyId}' already exists in this flow.", 422, 'reply_id_duplicate');
    }

    public static function maxDepthExceeded(int $max): self
    {
        return new self("Flow nodes can only be nested up to {$max} levels deep.", 422, 'max_depth_exceeded');
    }

    public static function buttonChildLimit(int $max): self
    {
        return new self("Button nodes can have a maximum of {$max} child options (WhatsApp limit).", 422, 'button_child_limit');
    }

    public static function listChildLimit(int $max): self
    {
        return new self("List nodes can have a maximum of {$max} child options (WhatsApp limit).", 422, 'list_child_limit');
    }

    public static function unauthorized(): self
    {
        return new self('You do not have permission to modify this node.', 403, 'unauthorized');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message'    => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->statusCode);
    }
}
