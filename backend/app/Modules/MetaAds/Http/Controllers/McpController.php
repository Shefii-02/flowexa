<?php

namespace App\Modules\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaAds\Mcp\McpToolRegistry;
use Illuminate\Http\{JsonResponse, Request};

/**
 * Model Context Protocol server for Meta Ads — one JSON-RPC 2.0 endpoint an AI agent (Claude
 * Desktop, an SDK client, this platform's own agent) can connect to and drive campaigns through.
 *
 * Transport: plain HTTP POST of a single JSON-RPC message (the "Streamable HTTP" transport without
 * SSE — sufficient for request/response tools). Auth is the same bearer JWT as the rest of the
 * meta-ads API, so every call is already company-scoped to the token's user.
 *
 * Implemented methods: initialize, notifications/initialized, ping, tools/list, tools/call.
 */
class McpController extends Controller
{
    private const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(private readonly McpToolRegistry $tools) {}

    public function handle(Request $request): JsonResponse
    {
        $body = $request->json()->all();

        // A JSON-RPC batch is an array of messages.
        if (array_is_list($body) && $body !== []) {
            return response()->json(array_values(array_filter(array_map(fn ($m) => $this->dispatch($m), $body))));
        }

        $response = $this->dispatch($body);
        return response()->json($response ?? ['jsonrpc' => '2.0', 'id' => null, 'result' => null], $response === null ? 202 : 200);
    }

    /** @return array|null  null for notifications (no response) */
    private function dispatch(array $msg): ?array
    {
        $id     = $msg['id'] ?? null;
        $method = $msg['method'] ?? '';
        $params = $msg['params'] ?? [];

        // Notifications have no id and get no response.
        if (!array_key_exists('id', $msg)) {
            return null;
        }

        return match ($method) {
            'initialize'   => $this->ok($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => ['name' => 'waapi-meta-ads', 'version' => '1.0.0'],
                'instructions'    => 'Tools for managing this company\'s Meta (Facebook/Instagram) ad campaigns, '
                                   . 'audiences, insights and lead-ad leads. Writes create or change status only — nothing deletes.',
            ]),
            'ping'         => $this->ok($id, (object) []),
            'tools/list'   => $this->ok($id, ['tools' => $this->tools->list()]),
            'tools/call'   => $this->callTool($id, $params),
            default        => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    private function callTool(mixed $id, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!$name) {
            return $this->error($id, -32602, 'Missing tool name');
        }
        $result = $this->tools->call($name, $params['arguments'] ?? []);
        return $this->ok($id, $result);
    }

    private function ok(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
