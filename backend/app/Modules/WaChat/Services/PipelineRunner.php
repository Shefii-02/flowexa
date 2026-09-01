<?php

namespace App\Modules\WaChat\Services;

use App\Modules\WaChat\Models\AiPipeline;
use App\Modules\WaChat\Models\AiPipelineRun;
use App\Modules\WaChat\Jobs\SendAutomationMessage;
use App\Modules\WaChat\Services\Rag\RagOrchestrator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PipelineRunner
{
    public function __construct(private readonly RagOrchestrator $rag) {}

    public function run(AiPipeline $pipeline, array $triggerData = [], string $triggeredBy = 'manual'): AiPipelineRun
    {
        $run = AiPipelineRun::create([
            'pipeline_id'  => $pipeline->id,
            'company_id'   => $pipeline->company_id,
            'triggered_by' => $triggeredBy,
            'trigger_data' => $triggerData,
            'status'       => 'running',
            'started_at'   => now(),
            'steps_log'    => [],
        ]);

        $stepsLog = [];
        $context  = array_merge(['trigger_data' => $triggerData], $triggerData);

        try {
            foreach ($pipeline->steps as $index => $step) {
                $stepResult = $this->executeStep($step, $context, $pipeline->company_id);
                $stepsLog[] = [
                    'step'    => $index + 1,
                    'type'    => $step['type'] ?? 'unknown',
                    'status'  => $stepResult['success'] ? 'ok' : 'error',
                    'result'  => $stepResult,
                ];

                // Merge step output into context for subsequent steps
                if (!empty($stepResult['output'])) {
                    $context = array_merge($context, $stepResult['output']);
                }

                // Stop on hard failure
                if (!$stepResult['success'] && ($step['stop_on_error'] ?? false)) {
                    break;
                }
            }

            $run->update([
                'status'       => 'completed',
                'steps_log'    => $stepsLog,
                'result'       => $context,
                'completed_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error("PipelineRunner #{$pipeline->id}: " . $e->getMessage());
            $run->update([
                'status'        => 'failed',
                'steps_log'     => $stepsLog,
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
        }

        return $run;
    }

    private function executeStep(array $step, array $context, int $companyId): array
    {
        $type = $step['type'] ?? 'noop';

        return match($type) {
            'send_message'   => $this->stepSendMessage($step, $context),
            'http_request'   => $this->stepHttpRequest($step, $context),
            'rag_query'      => $this->stepRagQuery($step, $context, $companyId),
            'condition'      => $this->stepCondition($step, $context),
            'set_variable'   => $this->stepSetVariable($step, $context),
            'delay'          => $this->stepDelay($step),
            default          => ['success' => true, 'output' => [], 'note' => 'noop'],
        };
    }

    private function stepSendMessage(array $step, array $context): array
    {
        $phone   = $this->interpolate($step['phone'] ?? $context['contact_phone'] ?? '', $context);
        $message = $this->interpolate($step['message'] ?? '', $context);
        $session = $step['session_id'] ?? $context['session_id'] ?? '';

        if (!$phone || !$message || !$session) {
            return ['success' => false, 'error' => 'Missing phone/message/session'];
        }

        dispatch(new SendAutomationMessage(
            companyId: (int) ($context['company_id'] ?? 0),
            sessionId: $session,
            chatId:    str_ends_with($phone, '@c.us') ? $phone : $phone . '@c.us',
            message:   $message,
            ruleId:    null,
            ruleType:  'pipeline',
            phone:     preg_replace('/@.*/', '', $phone),
        ));

        return ['success' => true, 'output' => ['message_sent' => true]];
    }

    private function stepHttpRequest(array $step, array $context): array
    {
        $url     = $this->interpolate($step['url'] ?? '', $context);
        $method  = strtoupper($step['method'] ?? 'GET');
        $payload = $step['body'] ?? [];

        if (!$url) return ['success' => false, 'error' => 'Missing URL'];

        try {
            $req = Http::timeout(15);

            if (!empty($step['headers'])) {
                $req = $req->withHeaders($step['headers']);
            }

            $response = match($method) {
                'POST'   => $req->post($url, $payload),
                'PUT'    => $req->put($url, $payload),
                'PATCH'  => $req->patch($url, $payload),
                'DELETE' => $req->delete($url),
                default  => $req->get($url, $payload),
            };

            $responseKey = $step['output_key'] ?? 'http_response';

            return [
                'success' => $response->successful(),
                'output'  => [$responseKey => $response->json()],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function stepRagQuery(array $step, array $context, int $companyId): array
    {
        $query   = $this->interpolate($step['query'] ?? $context['message'] ?? '', $context);
        $phone   = $context['contact_phone'] ?? 'unknown';
        $session = $context['session_id']    ?? '';

        if (!$query) return ['success' => false, 'error' => 'Empty query'];

        $result = $this->rag->answer(
            query:         $query,
            contactPhone:  $phone,
            wahaSessionId: $session,
            companyId:     $companyId,
            aiConfig:      $step['ai_config'] ?? [],
        );

        return ['success' => true, 'output' => ['rag_response' => $result['response'], 'rag_status' => $result['status']]];
    }

    private function stepCondition(array $step, array $context): array
    {
        $variable  = $this->interpolate($step['variable'] ?? '', $context);
        $operator  = $step['operator'] ?? 'equals';
        $value     = $step['value'] ?? '';

        $matches = match($operator) {
            'equals'       => $variable === $value,
            'not_equals'   => $variable !== $value,
            'contains'     => str_contains((string)$variable, (string)$value),
            'not_contains' => !str_contains((string)$variable, (string)$value),
            'empty'        => empty($variable),
            'not_empty'    => !empty($variable),
            default        => false,
        };

        return ['success' => true, 'output' => ['condition_result' => $matches]];
    }

    private function stepSetVariable(array $step, array $context): array
    {
        $key   = $step['key']   ?? null;
        $value = $this->interpolate($step['value'] ?? '', $context);

        if (!$key) return ['success' => false, 'error' => 'Missing key'];

        return ['success' => true, 'output' => [$key => $value]];
    }

    private function stepDelay(array $step): array
    {
        $seconds = (int) ($step['seconds'] ?? 1);
        if ($seconds > 0 && $seconds <= 30) {
            sleep($seconds);
        }
        return ['success' => true, 'output' => []];
    }

    private function interpolate(string $template, array $context): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($context) {
            return $context[$m[1]] ?? $m[0];
        }, $template);
    }
}
