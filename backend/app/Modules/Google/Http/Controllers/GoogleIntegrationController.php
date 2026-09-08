<?php

namespace App\Modules\Google\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\GoogleIntegration;
use App\Models\GoogleSheetSync;
use App\Modules\Google\GoogleClient;
use App\Modules\Google\GoogleSheetSyncService;
use Illuminate\Http\{JsonResponse, Request, RedirectResponse};
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class GoogleIntegrationController extends Controller
{
    public function __construct(
        private readonly GoogleClient $google,
        private readonly GoogleSheetSyncService $sync,
    ) {}

    /** Authed — returns the Google consent URL for the current company. */
    public function connectUrl(): JsonResponse
    {
        if (!$this->google->configured()) {
            return response()->json(['message' => 'Google is not configured on this server (missing client id/secret).'], 422);
        }
        // Signed, short-lived state so the public callback can trust which company it belongs to.
        $state = Crypt::encryptString(json_encode([
            'company_id' => auth()->user()->company_id,
            'user_id'    => auth()->id(),
            'ts'         => now()->timestamp,
        ]));
        return response()->json(['url' => $this->google->authUrl($state)]);
    }

    /** Public — Google redirects the browser here after consent. */
    public function callback(Request $request): RedirectResponse
    {
        $frontend = config('app.frontend_url', config('app.url'));
        try {
            $state = json_decode(Crypt::decryptString($request->query('state', '')), true);
            abort_unless(is_array($state) && !empty($state['company_id']), 400);
            abort_if(now()->timestamp - ($state['ts'] ?? 0) > 900, 400, 'expired');

            $tokens = $this->google->exchangeCode($request->query('code', ''));

            $integration = GoogleIntegration::updateOrCreate(
                ['company_id' => $state['company_id']],
                [
                    'connected_by'     => $state['user_id'] ?? null,
                    'google_email'     => $tokens['email'],
                    'access_token'     => $tokens['access_token'],
                    'refresh_token'    => $tokens['refresh_token'] ?: null,
                    'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                    'scopes'           => GoogleClient::SCOPES,
                    'is_active'        => true,
                    'last_error'       => null,
                ]
            );

            // Only need a fresh folder if we don't have one, or the refresh token was replaced.
            if (!$integration->drive_folder_id) {
                $folder = $this->google->createFolder($integration, config('app.name') . ' — Leads');
                $integration->update(['drive_folder_id' => $folder['id'], 'drive_folder_url' => $folder['url']]);
            }

            return redirect()->away(rtrim($frontend, '/') . '/settings/integrations?google=connected');
        } catch (\Throwable $e) {
            return redirect()->away(rtrim($frontend, '/') . '/settings/integrations?google=error&message=' . urlencode($e->getMessage()));
        }
    }

    public function status(): JsonResponse
    {
        $i = GoogleIntegration::where('company_id', auth()->user()->company_id)->with('syncs')->first();
        return response()->json([
            'configured'  => $this->google->configured(),
            'integration' => $i,
        ]);
    }

    public function disconnect(): JsonResponse
    {
        GoogleIntegration::where('company_id', auth()->user()->company_id)->delete();
        return response()->json(['message' => 'Google disconnected. Existing sheets stay in your Drive.']);
    }

    public function createSync(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name'   => ['required', 'string', 'max:150'],
            'source' => ['required', Rule::in(['leads', 'widget', 'meta_leads', 'instagram', 'whatsapp'])],
        ]);
        $i = $this->integration();

        try {
            $sheet = $this->google->createSpreadsheet(
                $i,
                $d['name'],
                $i->drive_folder_id,
                $this->sync->headerFor($d['source']),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sync = GoogleSheetSync::create([
            'company_id'            => $i->company_id,
            'google_integration_id' => $i->id,
            'name'                  => $d['name'],
            'source'                => $d['source'],
            'spreadsheet_id'        => $sheet['id'],
            'sheet_url'             => $sheet['url'],
        ]);

        // First fill immediately so the sheet isn't empty.
        try { $this->sync->syncOne($sync); } catch (\Throwable $e) { /* logged in service */ }

        return response()->json(['message' => 'Sheet created and linked.', 'sync' => $sync->fresh()], 201);
    }

    public function syncNow(int $id): JsonResponse
    {
        $sync = $this->findSync($id);
        try {
            $n = $this->sync->syncOne($sync);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => "{$n} new row(s) added.", 'sync' => $sync->fresh()]);
    }

    public function deleteSync(int $id): JsonResponse
    {
        $this->findSync($id)->delete();
        return response()->json(['message' => 'Sync removed. The sheet stays in your Drive.']);
    }

    // ── Drive as external file storage (listing media) ────────────────────

    public function driveUpload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200', 'mimes:jpg,jpeg,png,gif,webp,mp4,mov,pdf'],
        ]);
        $i = $this->integration();
        $file = $request->file('file');

        try {
            $result = $this->google->uploadFile(
                $i,
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
                $file->getMimeType() ?: 'application/octet-stream',
                $i->drive_folder_id,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['file' => $result], 201);
    }

    public function driveFiles(): JsonResponse
    {
        $i = $this->integration();
        try {
            return response()->json(['files' => $this->google->listFiles($i, $i->drive_folder_id, 120)]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function integration(): GoogleIntegration
    {
        return GoogleIntegration::where('company_id', auth()->user()->company_id)->firstOrFail();
    }

    private function findSync(int $id): GoogleSheetSync
    {
        return GoogleSheetSync::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
