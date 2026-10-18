<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /backend/logs — browser view of storage/logs/laravel.log, newest first, one box per entry.
 * Guarded by the LOG_VIEWER_KEY env value (page is 404 when it is not set).
 */
class LogViewerController extends Controller
{
    private const TAIL_BYTES = 3_000_000;

    private function file(): string { return storage_path('logs/laravel.log'); }

    public function index(): Response
    {
        return response(view('admin.logs', ['key' => request('key')])->render());
    }

    public function entries(Request $request): JsonResponse
    {
        $level  = strtolower((string) $request->query('level', ''));
        $search = trim((string) $request->query('q', ''));
        $limit  = min(max((int) $request->query('limit', 200), 1), 1000);
        $file   = $this->file();

        if (!is_file($file)) { return response()->json(['entries' => [], 'size' => 0]); }

        $size = filesize($file);
        $fh = fopen($file, 'r');
        fseek($fh, max(0, $size - self::TAIL_BYTES));
        $raw = stream_get_contents($fh);
        fclose($fh);

        // Split at each "[YYYY-MM-DD HH:MM:SS] env.LEVEL:" header; a cut first chunk is dropped.
        $parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] )/m', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $entries = [];
        foreach (array_reverse($parts) as $p) {
            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)$/s', $p, $m)) { continue; }
            if ($level && strtolower($m[3]) !== $level) { continue; }
            if ($search !== '' && stripos($p, $search) === false) { continue; }
            $entries[] = ['time' => $m[1], 'env' => $m[2], 'level' => strtoupper($m[3]), 'message' => mb_substr(rtrim($m[4]), 0, 6000)];
            if (count($entries) >= $limit) { break; }
        }

        return response()->json(['entries' => $entries, 'size' => $size]);
    }

    public function clear(): JsonResponse
    {
        if (is_file($this->file())) { file_put_contents($this->file(), ''); }
        return response()->json(['message' => 'Log cleared.']);
    }
}
