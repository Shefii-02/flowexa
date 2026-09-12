<?php

namespace App\Http\Controllers;

use App\Models\ErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives crash reports from the React app's global error handler (window.onerror,
 * unhandledrejection, and the root ErrorBoundary — see frontend/src/errorReporter.ts).
 * Deliberately outside auth: a crash can happen before login or after a token expires,
 * and losing exactly those reports would blind the dashboard to its worst-case errors.
 * A valid JWT, when present, still gets attached so the row can be tied to a company/user.
 */
class FrontendErrorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'         => ['required', 'string', 'max:2000'],
            'stack'           => ['nullable', 'string', 'max:8000'],
            'component_stack' => ['nullable', 'string', 'max:4000'],
            'url'             => ['nullable', 'string', 'max:500'],
        ]);

        $user = null;
        try {
            $user = auth('api')->user();
        } catch (\Throwable) {
            // no valid token — record the crash anonymously rather than dropping it
        }

        $trace = trim(
            ($data['stack'] ?? '')
            . (!empty($data['component_stack']) ? "\n\nComponent stack:\n" . $data['component_stack'] : '')
        );

        ErrorLog::create([
            'company_id'      => $user?->company_id,
            'user_id'         => $user?->id,
            'actor_role'      => $user?->role?->name,
            'source'          => ErrorLog::SOURCE_FRONTEND,
            'exception_class' => 'ReactError',
            'message'         => $data['message'],
            'url'             => $data['url'] ?? null,
            'trace'           => $trace !== '' ? $trace : null,
            'created_at'      => now(),
        ]);

        return response()->json(['message' => 'Recorded.'], 201);
    }
}
