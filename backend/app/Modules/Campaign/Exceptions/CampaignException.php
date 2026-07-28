<?php

namespace App\Modules\Campaign\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class CampaignException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 400,
        private readonly ?string $errorCode = null,
    ) { parent::__construct($message); }

    public static function notFound(): self          { return new self('Campaign not found.', 404, 'campaign_not_found'); }
    public static function notEditable(string $s): self { return new self("Cannot edit a {$s} campaign.", 422, 'not_editable'); }
    public static function notLaunchable(string $s): self { return new self("Cannot launch a {$s} campaign.", 422, 'not_launchable'); }
    public static function cannotDeleteRunning(): self { return new self('Pause the campaign before deleting.', 422, 'cannot_delete_running'); }
    public static function noContacts(): self        { return new self('No opted-in contacts found for this campaign.', 422, 'no_contacts'); }
    public static function notRunning(): self        { return new self('Campaign is not running.', 422, 'not_running'); }
    public static function notPaused(): self         { return new self('Campaign is not paused.', 422, 'not_paused'); }
    public static function noFailedMessages(): self  { return new self('No failed messages to resend.', 422, 'no_failed'); }
    public static function insufficientBalance(int $have, int $need): self {
        return new self("Insufficient balance. Need {$need} messages, have {$have}.", 402, 'insufficient_balance');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_code' => $this->errorCode], $this->statusCode);
    }
}
