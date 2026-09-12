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
            'name'             => ['required', 'string', 'max:150'],
            'source'           => ['required', Rule::in(['leads', 'widget', 'meta_leads', 'instagram', 'whatsapp'])],
            // Presets: 10 min / 30 min / 1h / 6h / 24h. Leads default to a tight 10-minute
            // cadence; message logs are heavier so 6h+ is the sane floor in the UI.
            'interval_minutes' => ['nullable', 'integer', Rule::in([10, 30, 60, 360, 1440])],
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
            'interval_minutes'      => $d['interval_minutes'] ?? 10,
        ]);

        // First fill immediately so the sheet isn't empty.
        try { $this->sync->syncOne($sync); } catch (\Throwable $e) { /* logged in service */ }

        return response()->json(['message' => 'Sheet created and linked.', 'sync' => $sync->fresh()], 201);
    }

    public function updateSync(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'interval_minutes' => ['sometimes', 'integer', Rule::in([10, 30, 60, 360, 1440])],
            'is_active'        => ['sometimes', 'boolean'],
        ]);
        $sync = $this->findSync($id);
        $sync->update($d);
        return response()->json(['sync' => $sync->fresh()]);
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

    // ── Drive — full file manager (browse/upload/rename/delete anywhere in the account) ────

    /** Any file type, up to 100MB — this is a general-purpose Drive browser, not just media. */
    public function driveUpload(Request $request): JsonResponse
    {
        $d = $request->validate([
            'file'      => ['required', 'file', 'max:102400'],
            'folder_id' => ['nullable', 'string', 'max:100'],
        ]);
        $i = $this->integration();
        $file = $request->file('file');

        try {
            $result = $this->google->uploadFile(
                $i,
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName(),
                $file->getMimeType() ?: 'application/octet-stream',
                $d['folder_id'] ?? $i->drive_folder_id,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['file' => $result], 201);
    }

    public function driveFiles(Request $request): JsonResponse
    {
        $i = $this->integration();
        try {
            $files = $this->google->listFiles($i, $request->query('folder_id'), 150, $request->query('q'));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json([
            'files'     => $files,
            'folder_id' => $request->query('folder_id') ?: \App\Modules\Google\GoogleClient::ROOT,
        ]);
    }

    public function driveCreateFolder(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name'      => ['required', 'string', 'max:200'],
            'folder_id' => ['nullable', 'string', 'max:100'],
        ]);
        $i = $this->integration();
        try {
            $folder = $this->google->createFolder($i, $d['name'], $d['folder_id'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['folder' => $folder], 201);
    }

    public function driveRename(Request $request, string $fileId): JsonResponse
    {
        $d = $request->validate(['name' => ['required', 'string', 'max:200']]);
        $i = $this->integration();
        try {
            $file = $this->google->renameFile($i, $fileId, $d['name']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['file' => $file]);
    }

    public function driveDelete(string $fileId): JsonResponse
    {
        $i = $this->integration();
        try {
            $this->google->trashFile($i, $fileId);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Moved to Drive trash.']);
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
