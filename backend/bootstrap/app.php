<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Modules\WaChat\Console\Commands\ProcessScheduledMessages::class,
        \App\Modules\WaChat\Console\Commands\ProcessAutomations::class,
        \App\Modules\WaChat\Console\Commands\ProcessFollowUps::class,
        \App\Modules\WaChat\Console\Commands\ResetMonthlyUsage::class,
        \App\Console\Commands\UpdateConversationSummaries::class,
        \App\Console\Commands\RecalculateLeadScores::class,
        \App\Console\Commands\CleanupOldAnalyses::class,
        \App\Console\Commands\SetupExistingCompanies::class,
    ])
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        $schedule->command('wachat:process-scheduled-messages')->everyMinute();
        $schedule->command('wachat:process-automations')->everyFiveMinutes();
        $schedule->command('wachat:process-followups')->everyFifteenMinutes();
        $schedule->command('ai:reset-monthly-tokens')->monthlyOn(1, '00:00');
        $schedule->command('ai:update-summaries')->everySixHours();
        $schedule->command('ai:recalculate-scores')->dailyAt('02:00');
        $schedule->command('ai:cleanup-analyses')->weeklyOn(0, '03:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        //
        // ── Global API middleware ──────────────────────────────────────────────
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);


        // ── Named middleware aliases ───────────────────────────────────────────
        $middleware->alias([
            // Auth
            'jwt.auth'       => \App\Modules\Auth\Http\Middleware\JwtMiddleware::class,
            'jwt.refresh'    => \PHPOpenSourceSaver\JWTAuth\Http\Middleware\RefreshToken::class,
            'superadmin'     => \App\Http\Middleware\SuperAdmin::class,
            'superadmin.only'=> \App\Http\Middleware\SuperAdminOnly::class,
            'company.active' => \App\Modules\Auth\Http\Middleware\EnsureCompanyActive::class,
            'permission'     => \App\Modules\Auth\Http\Middleware\CheckPermission::class,
            'plan.limit'      => \App\Http\Middleware\PlanLimitMiddleware::class,

            // OTP external API key auth
            'otp.auth'       => \App\Modules\Otp\Http\Middleware\OtpApiAuth::class,
        ]);
    })
    ->withProviders([
        // ── Module Service Providers ───────────────────────────────────────────
        \App\Modules\Auth\AuthServiceProvider::class,
        \App\Modules\Staff\StaffServiceProvider::class,
        \App\Modules\Contact\ContactServiceProvider::class,
        \App\Modules\Flow\FlowServiceProvider::class,
        \App\Modules\Wallet\WalletServiceProvider::class,
        \App\Modules\Campaign\CampaignServiceProvider::class,
        \App\Modules\Lead\LeadServiceProvider::class,
        \App\Modules\Otp\OtpServiceProvider::class,
        \App\Modules\Webhook\WebhookServiceProvider::class,
        \App\Modules\Analytics\AnalyticsServiceProvider::class,
        \App\Modules\Settings\SettingsServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {

        // Force JSON responses for API routes
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*')
        );

        // Register module exception renderers
        $moduleExceptions = [
            \App\Modules\Auth\Exceptions\AuthException::class,
            \App\Modules\Staff\Exceptions\StaffException::class,
            \App\Modules\Contact\Exceptions\ContactException::class,
            \App\Modules\Flow\Exceptions\FlowException::class,
            \App\Modules\Wallet\Exceptions\WalletException::class,
            \App\Modules\Campaign\Exceptions\CampaignException::class,
            \App\Modules\Lead\Exceptions\LeadException::class,
            \App\Modules\Otp\Exceptions\OtpException::class,
        ];

        foreach ($moduleExceptions as $exceptionClass) {
            $exceptions->render(function (\Throwable $e, Request $request) use ($exceptionClass) {
                if ($e instanceof $exceptionClass) {
                    return $e->render($request);
                }

                return null;
            });
        }

        // Global API exception handler
        $exceptions->render(function (\Throwable $e, Request $request) {

            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
                return response()->json([
                    'message' => 'Forbidden.',
                ], 403);
            }

            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return response()->json([
                    'message' => class_basename($e->getModel()) . ' not found.',
                ], 404);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                return response()->json([
                    'message' => 'Route not found.',
                ], 404);
            }

            if ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
                return response()->json([
                    'message' => 'Method not allowed.',
                ], 405);
            }

            if ($e instanceof \Illuminate\Database\QueryException) {
                \Illuminate\Support\Facades\Log::error($e);

                return response()->json([
                    'message' => 'Database error occurred.',
                ], 500);
            }

            if (config('app.debug')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => collect($e->getTrace())->take(5)->values(),
                ], 500);
            }

            return response()->json([
                'message' => 'Server error. Please try again later.',
            ], 500);
        });
        // ->withExceptions(function (Exceptions $exceptions): void {
        //     $exceptions->shouldRenderJsonWhen(
        //         fn (Request $request) => $request->is('api/*'),
        //     );
    })->create();
