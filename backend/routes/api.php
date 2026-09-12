<?php

use App\Http\Controllers\ExtensionController;
use App\Modules\Analytics\Http\Controllers\AdvancedAnalyticsController;
use Illuminate\Support\Facades\Route;

use App\Modules\Analytics\Http\Controllers\AnalyticsController;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\CompanyController;
use App\Modules\Auth\Http\Controllers\PushNotificationController;
use App\Modules\Blacklist\Http\Controllers\BlacklistController;
use App\Modules\Campaign\Http\Controllers\CampaignContactController;
use App\Modules\Campaign\Http\Controllers\CampaignController;
use App\Modules\Contact\Http\Controllers\ContactController;
use App\Modules\Contact\Http\Controllers\LabelController;
use App\Modules\Conversation\Http\Controllers\ConversationController;
use App\Modules\Flow\Http\Controllers\FlowAnalyticsController;
use App\Modules\Flow\Http\Controllers\FlowBuilderController;
use App\Modules\Flow\Http\Controllers\FlowController;
use App\Modules\Flow\Http\Controllers\FlowImportExportController;
use App\Modules\Flow\Http\Controllers\FlowNodeController;
use App\Modules\Lead\Http\Controllers\LeadController;
use App\Modules\Lead\Http\Controllers\LeadCategoryController;
use App\Modules\Lead\Http\Controllers\LeadNoteController;
use App\Modules\Lead\Http\Controllers\LeadReportController;
use App\Modules\Lead\Http\Controllers\WorkingHoursController;
use App\Modules\Crm\Http\Controllers\DealController;
use App\Modules\Crm\Http\Controllers\TaskController;
use App\Modules\Crm\Http\Controllers\SegmentController;
use App\Modules\Hr\Http\Controllers\AttendanceController;
use App\Modules\Hr\Http\Controllers\LeaveController;
use App\Modules\Hr\Http\Controllers\HrConfigController;
use App\Modules\Hr\Http\Controllers\PayrollController;
use App\Modules\Hr\Http\Controllers\IncentiveController;
use App\Modules\Lead\Http\Controllers\LeadAssignmentController;
use App\Modules\Otp\Http\Controllers\OtpController;
use App\Modules\PhoneNumber\Http\Controllers\PhoneNumberController;
use App\Modules\PlanPurchase\Http\Controllers\PlanPurchaseController;
use App\Modules\Report\Http\Controllers\ReportController;
use App\Modules\Settings\Http\Controllers\MessageLogController;
use App\Modules\Settings\Http\Controllers\PrebuiltTemplateController;
use App\Modules\Settings\Http\Controllers\SettingsController;
use App\Modules\Settings\Http\Controllers\SuperAdminController;
use App\Modules\Staff\Http\Controllers\RoleController;
use App\Modules\Staff\Http\Controllers\StaffController;
use App\Modules\SuperadminStaff\Http\Controllers\SuperadminStaffController;
use App\Modules\Template\Http\Controllers\TemplateController;
use App\Modules\Wallet\Http\Controllers\PaymentController;
use App\Modules\Wallet\Http\Controllers\WalletController;
use App\Modules\Webhook\Http\Controllers\WebhookController;

use App\Modules\MetaAds\Http\Controllers\{
    MetaAdAccountController,
    MetaCampaignController,
    MetaAdSetController,
    MetaAudienceSetController,
    MetaLeadController,
    MetaAdsAiController,
    McpController,
    MetaCreativeController,
    MetaAdController,
    MetaInsightController,
    MetaMediaController,
    MetaWebhookController,
};
use App\Modules\Survey\Http\Controllers\SurveyFormController;
use App\Modules\WaChat\Http\Controllers\WahaSessionController;
use App\Modules\WaChat\Http\Controllers\WaChatAnalyticsController;
use App\Modules\WaChat\Http\Controllers\WahaWebhookConfigController;
use App\Modules\WaChat\Http\Controllers\MessageSenderController;
use App\Modules\WaChat\Http\Controllers\MediaLibraryController;
use App\Modules\WaChat\Http\Controllers\MediaFolderController;
use App\Modules\WaChat\Http\Controllers\WaChatTemplateController;
use App\Modules\WaChat\Http\Controllers\WaOtpServiceController;
use App\Modules\WaChat\Http\Controllers\WaOtpPublicController;
use App\Modules\WaCloud\Http\Controllers\WaCloudApiServiceController;
use App\Modules\WaCloud\Http\Controllers\WaCloudApiConfigController;
use App\Modules\WaCloud\Http\Controllers\WaCloudApiPublicController;
use App\Modules\WaCloud\Http\Controllers\WaCloudInboxAnalyticsController;
use App\Modules\WaCloud\Http\Controllers\WaCloudAutomationController;
use App\Modules\WaChat\Http\Controllers\WaExportController;
use App\Modules\WaChat\Http\Controllers\AutomationController;
use App\Modules\WaChat\Http\Controllers\KnowledgeBaseController;
use App\Modules\WaChat\Http\Controllers\PipelineController;
use App\Modules\WaChat\Http\Controllers\AiAgentController;
use App\Modules\WaChat\Http\Controllers\AgentPlaybookController;
use App\Modules\WaChat\Http\Controllers\AgentPlaybookTemplateController;
use App\Http\Controllers\CompanyApiKeyController;
use App\Http\Controllers\MetaAiController;
use App\Modules\CompanyRole\Http\Controllers\CompanyRoleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Auth Module Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ── Public ──────────────────────────────────────────────────────────────
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login',    [AuthController::class, 'login'])->name('login');
    });

    // ── Mobile app QR / PIN device login (public — the challenge is the credential) ──
    Route::prefix('device-auth')->group(function () {
        Route::post('check',          [\App\Modules\Auth\Http\Controllers\DeviceAuthController::class, 'check']);
        Route::post('claim',          [\App\Modules\Auth\Http\Controllers\DeviceAuthController::class, 'claim']);
        Route::post('remove-device',  [\App\Modules\Auth\Http\Controllers\DeviceAuthController::class, 'removeDevice']);

        Route::middleware('jwt.auth')->group(function () {
            Route::get('status',    [\App\Modules\Auth\Http\Controllers\DeviceAuthController::class, 'status']);
            Route::post('heartbeat', [\App\Modules\Auth\Http\Controllers\DeviceAuthController::class, 'heartbeat']);
            Route::post('logout',    [\App\Modules\Auth\Http\Controllers\DeviceAuthController::class, 'logout']);
        });
    });

    Route::middleware(['jwt.auth'])->group(function () {

        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me',       [AuthController::class, 'me'])->name('me');
            Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
            Route::post('logout',  [AuthController::class, 'logout'])->name('logout');
        });

        // ── Linked devices (web management) ──────────────────────────────────
        Route::prefix('devices')->group(function () {
            Route::get('/',                          [\App\Modules\Auth\Http\Controllers\DeviceController::class, 'index']);
            Route::get('/staff',                     [\App\Modules\Auth\Http\Controllers\DeviceController::class, 'staff']);
            Route::get('/user/{userId}',             [\App\Modules\Auth\Http\Controllers\DeviceController::class, 'userDevices']);
            Route::post('/login-challenge',          [\App\Modules\Auth\Http\Controllers\DeviceController::class, 'createChallenge']);
            Route::get('/login-challenge/{id}',      [\App\Modules\Auth\Http\Controllers\DeviceController::class, 'challengeStatus']);
            Route::post('/login-challenge/{id}/cancel', [\App\Modules\Auth\Http\Controllers\DeviceController::class, 'cancelChallenge']);
            Route::delete('/{id}',                   [\App\Modules\Auth\Http\Controllers\DeviceController::class, 'destroy']);
        });

        Route::prefix('superadmin')->name('superadmin.')->group(function () {

            Route::middleware(['superadmin'])->group(function () {

                Route::get('topup-packages',    [PlanPurchaseController::class, 'topupPackages']);

                Route::get('addons',            [PlanPurchaseController::class, 'superAdminAddons']);
                Route::get('dashboard',                        [SuperAdminController::class, 'dashboard'])->name('dashboard');
                Route::get('companies',                        [SuperAdminController::class, 'companies'])->name('companies.index');
                Route::post('companies',                       [SuperAdminController::class, 'createCompany'])->name('companies.store');
                Route::get('companies/{company}',              [SuperAdminController::class, 'showCompany'])->name('companies.show');
                Route::put('companies/{company}',              [SuperAdminController::class, 'updateCompany'])->name('companies.update');
                Route::delete('companies/{company}',           [SuperAdminController::class, 'deleteCompany'])->name('companies.destroy');
                Route::post('companies/{company}/top-up',      [SuperAdminController::class, 'topUp'])->name('companies.top-up');
                Route::post('companies/{company}/reset-api-key', [SuperAdminController::class, 'resetApiKey'])->name('companies.reset-api-key');
                Route::post('companies/{company}/impersonate', [SuperAdminController::class, 'impersonate'])->name('companies.impersonate');
                Route::patch('companies/{company}/status',     [SuperAdminController::class, 'updateStatus'])->name('companies.status');
                Route::get('plans',                            [SuperAdminController::class, 'plans'])->name('plans.index');
                Route::post('plans',                           [SuperAdminController::class, 'createPlan'])->name('plans.store');
                Route::put('plans/{plan}',                     [SuperAdminController::class, 'updatePlan'])->name('plans.update');
                Route::get('users',                            [SuperAdminController::class, 'users'])->name('users.index');
                Route::get('stats',                            [SuperAdminController::class, 'stats'])->name('stats');
                Route::get('billing',                          [SuperAdminController::class, 'billing'])->name('billing');

                // Staff
                Route::get('staff',           [SuperadminStaffController::class, 'index']); //->name('staff.index');
                Route::post('staff',          [SuperadminStaffController::class, 'store']); //->name('staff.store');
                Route::put('staff/{id}',      [SuperadminStaffController::class, 'update'])->name('staff.update');
                Route::delete('staff/{id}',   [SuperadminStaffController::class, 'destroy'])->name('staff.destroy');
                Route::patch('staff/{id}/toggle', [SuperadminStaffController::class, 'toggle'])->name('staff.toggle');

                // Platform-staff phone-app linked devices (same QR / PIN feature as company staff)
                Route::get('staff/{id}/devices',                 [SuperadminStaffController::class, 'devices'])->name('staff.devices');
                Route::post('staff/{id}/device-challenge',       [SuperadminStaffController::class, 'deviceChallenge'])->name('staff.device-challenge');
                Route::delete('staff/{id}/devices/{deviceId}',   [SuperadminStaffController::class, 'revokeDevice'])->name('staff.device-revoke');

                // Reports
                Route::get('reports/platform',          [ReportController::class, 'platformReport'])->name('reports.platform');
                Route::get('reports/company/{company}', [ReportController::class, 'companyReport'])->name('reports.company');
                Route::get('reports/purchases',         [ReportController::class, 'purchaseReport'])->name('reports.purchases');

                // Role permissions editor
                Route::get('permissions',         [SuperAdminController::class, 'permissions'])->name('permissions');
                Route::put('permissions/{roleId}', [SuperAdminController::class, 'updatePermissions'])->name('permissions.update');

                // Per-company permission management
                Route::get('companies/{company}/permissions',                 [SuperAdminController::class, 'companyPermissions']);
                Route::put('companies/{company}/roles/{roleId}/permissions',   [SuperAdminController::class, 'updateCompanyRolePermissions']);
                Route::post('companies/{company}/permissions/resync',          [SuperAdminController::class, 'resyncCompanyPermissions']);

                // Agent playbook category templates (LMS, Real Estate, Services…)
                Route::get('playbook-templates',         [AgentPlaybookTemplateController::class, 'index'])->name('playbook-templates.index');
                Route::post('playbook-templates',        [AgentPlaybookTemplateController::class, 'store'])->name('playbook-templates.store');
                Route::patch('playbook-templates/{id}',  [AgentPlaybookTemplateController::class, 'update'])->name('playbook-templates.update');
                Route::delete('playbook-templates/{id}', [AgentPlaybookTemplateController::class, 'destroy'])->name('playbook-templates.destroy');

                // Prebuilt WhatsApp template library (auth / utility / other)
                Route::get('prebuilt-templates',         [PrebuiltTemplateController::class, 'index'])->name('prebuilt-templates.index');
                Route::post('prebuilt-templates',        [PrebuiltTemplateController::class, 'store'])->name('prebuilt-templates.store');
                Route::patch('prebuilt-templates/{id}',  [PrebuiltTemplateController::class, 'update'])->name('prebuilt-templates.update');
                Route::delete('prebuilt-templates/{id}', [PrebuiltTemplateController::class, 'destroy'])->name('prebuilt-templates.destroy');

                // Exit impersonation
                Route::post('exit-impersonation', [SuperAdminController::class, 'exitImpersonation'])->name('exit-impersonation');
            });

            // Only superadmin (not superadmin_staff):
            Route::middleware(['superadmin.only'])->group(function () {
                Route::post('companies/{id}/impersonate', [SuperAdminController::class, 'impersonate']);
                Route::delete('plans/{id}',               [SuperAdminController::class, 'deletePlan']);
                Route::post('staff',                      [SuperadminStaffController::class, 'store']);
                Route::delete('staff/{id}',               [SuperadminStaffController::class, 'destroy']);
                Route::put('permissions/{roleId}',        [SuperAdminController::class, 'updatePermissions']);
            });
        });



        // Company profile (owner/admin)
        Route::prefix('company')->name('company.')->middleware('permission:settings.manage')->group(function () {
            Route::get('/',                  [CompanyController::class, 'show'])->name('show');
            Route::put('/',                  [CompanyController::class, 'update'])->name('update');
            Route::post('wa-credentials',    [CompanyController::class, 'updateWaCredentials'])->name('wa-credentials');
            Route::post('regenerate-token',  [CompanyController::class, 'regenerateToken'])->name('regenerate-token');
        });

        // ── Company Settings (owner/admin) ───────────────────────────────────────
        Route::prefix('settings')->name('settings.')->middleware('permission:settings.manage')->group(function () {
            Route::get('/',                   [SettingsController::class, 'index'])->name('index');
            Route::put('/',                   [SettingsController::class, 'update'])->name('update');
            Route::post('wa-credentials',     [SettingsController::class, 'updateWaCredentials'])->name('wa-credentials');
            Route::post('regenerate-token',   [SettingsController::class, 'regenerateToken'])->name('regenerate-token');
            Route::post('logo',               [SettingsController::class, 'uploadLogo'])->name('logo');
            Route::get('otp-credentials',     [SettingsController::class, 'getOtpCredentials'])->name('otp-credentials');

            Route::get('verify-wa',    [SettingsController::class, 'verifyWa']);
            Route::post('test-send',   [SettingsController::class, 'testSend']);
            Route::get('webhook-logs', [SettingsController::class, 'webhookLogs']);
        });

        // ── Message Logs ─────────────────────────────────────────────────────────
        Route::get('message-logs', [MessageLogController::class, 'index'])->name('message-logs.index');
        Route::get('message-logs/{log}', [MessageLogController::class, 'show'])->name('message-logs.show');
        // ── Roles ─────────────────────────────────────────────────────────────────
        Route::prefix('roles')->name('roles.')->group(function () {
            // READ — available to all authenticated users
            Route::get('/',           [RoleController::class, 'index'])->name('index');
            // /permissions MUST come before /{role} to avoid route conflict
            Route::get('/permissions',[RoleController::class, 'allPermissions'])->name('permissions');
            Route::get('/{role}',     [RoleController::class, 'show'])->name('show');

            // WRITE — restricted to role managers
            Route::middleware('permission:roles.manage')->group(function () {
                Route::post('/',                         [RoleController::class, 'store'])->name('store');
                Route::post('/sync-catalogue',           [RoleController::class, 'syncCatalogue'])->name('sync-catalogue');
                Route::put('/{role}',                    [RoleController::class, 'update'])->name('update');
                Route::delete('/{role}',                 [RoleController::class, 'destroy'])->name('destroy');
                Route::post('/{role}/reset-permissions', [RoleController::class, 'resetToDefaults'])->name('reset-permissions');
            });
        });

        // ── Staff ────────────────────────────────────────────────────────────────
        Route::prefix('staff')->name('staff.')->group(function () {

            // View staff (team_lead, admin, owner)
            Route::middleware('permission:staff.view')->group(function () {
                Route::get('/',              [StaffController::class, 'index'])->name('index');
                Route::get('/performance',   [StaffController::class, 'performance'])->name('performance');
                Route::get('/departments',   [StaffController::class, 'departments'])->name('departments');
                Route::get('/{staff}',       [StaffController::class, 'show'])->name('show');
            });

            // Create staff (admin, owner)
            Route::post('/', [StaffController::class, 'store'])
                ->middleware('permission:staff.create')
                ->name('store');

            // Edit staff (admin, owner)
            Route::put('/{staff}', [StaffController::class, 'update'])
                ->middleware('permission:staff.edit')
                ->name('update');

            // Toggle active status (admin, owner)
            Route::patch('/{staff}/toggle-active', [StaffController::class, 'toggleActive'])
                ->middleware('permission:staff.edit')
                ->name('toggle-active');

            // Reset password (admin, owner)
            Route::patch('/{staff}/reset-password', [StaffController::class, 'resetPassword'])
                ->middleware('permission:staff.edit')
                ->name('reset-password');

            // Delete staff (owner only)
            Route::delete('/{staff}', [StaffController::class, 'destroy'])
                ->middleware('permission:staff.delete')
                ->name('destroy');

            Route::get('/{staff}/performance',   [StaffController::class, 'performance'])->name('performance');
        });



        // 'company.active'
        Route::prefix('analytics')->name('analytics.')->middleware(['jwt.auth',])->group(function () {
            Route::get('overview',  [AnalyticsController::class, 'overview'])->name('overview');
            Route::get('campaigns', [AnalyticsController::class, 'campaigns'])->name('campaigns');
            Route::get('flows',     [AnalyticsController::class, 'flows'])->name('flows');
            Route::get('staff',     [AnalyticsController::class, 'staff'])->name('staff');
            Route::get('wallet',    [AnalyticsController::class, 'wallet'])->name('wallet');
            Route::get('leads',     [AnalyticsController::class, 'leads'])->name('leads');
            Route::get('messages',  [AnalyticsController::class, 'messages'])->name('messages');
        });

        Route::prefix('campaigns')->name('campaigns.')->group(function () {

            Route::middleware('permission:campaigns.view')->group(function () {
                Route::get('/',              [CampaignController::class, 'index'])->name('index');
                Route::get('/{campaign}',    [CampaignController::class, 'show'])->name('show');
                Route::get('/{campaign}/contacts', [CampaignContactController::class, 'index'])->name('contacts');
                Route::get('/{campaign}/stats',    [CampaignController::class, 'stats'])->name('stats');
            });

            Route::post('/', [CampaignController::class, 'store'])
                ->middleware('permission:campaigns.create')->name('store');

            Route::put('/{campaign}', [CampaignController::class, 'update'])
                ->middleware('permission:campaigns.edit')->name('update');

            Route::delete('/{campaign}', [CampaignController::class, 'destroy'])
                ->middleware('permission:campaigns.delete')->name('destroy');

            Route::middleware('permission:campaigns.launch')->group(function () {
                Route::post('/{campaign}/launch',       [CampaignController::class, 'launch'])->name('launch');
                Route::post('/{campaign}/pause',        [CampaignController::class, 'pause'])->name('pause');
                Route::post('/{campaign}/resume',       [CampaignController::class, 'resume'])->name('resume');
                Route::post('/{campaign}/resend-failed', [CampaignController::class, 'resendFailed'])->name('resend-failed');
            });
        });

        Route::prefix('otp')->middleware('otp.auth')->group(function () {
            Route::post('send',   [OtpController::class, 'send'])->name('otp.send');
            Route::post('verify', [OtpController::class, 'verify'])->name('otp.verify');
        });

        Route::prefix('lead-categories')->name('lead-categories.')->group(function () {
            Route::get('/',           [LeadCategoryController::class, 'index'])->name('index');
            Route::post('/',          [LeadCategoryController::class, 'store'])->name('store');
            Route::get('/{category}', [LeadCategoryController::class, 'show'])->name('show');
            Route::put('/{category}', [LeadCategoryController::class, 'update'])->name('update');
            Route::delete('/{category}', [LeadCategoryController::class, 'destroy'])->name('destroy');
        });

        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('conversations/{id}/messages', [ConversationController::class, 'messages']);
        Route::post('conversations/{id}/claim', [ConversationController::class, 'claim']);
        Route::post('conversations/{id}/release', [ConversationController::class, 'release']);
        Route::post('conversations/{id}/messages', [ConversationController::class, 'send']);

        Route::prefix('survey-forms')->group(function () {
            Route::get('/',                 [SurveyFormController::class, 'index']);
            Route::post('/',                [SurveyFormController::class, 'store']);
            Route::get('/{id}',             [SurveyFormController::class, 'show']);
            Route::put('/{id}',             [SurveyFormController::class, 'update']);
            Route::delete('/{id}',          [SurveyFormController::class, 'destroy']);
            Route::post('/{id}/duplicate',  [SurveyFormController::class, 'duplicate']);
            Route::post('/{id}/publish-flow', [SurveyFormController::class, 'publishFlow']);
            Route::get('/{id}/analytics',   [SurveyFormController::class, 'analytics']);
            Route::get('/{id}/responses',   [SurveyFormController::class, 'responses']);
            Route::get('/{id}/responses/export',   [SurveyFormController::class, 'exportResponses']);
            Route::post('/{id}/responses/to-label', [SurveyFormController::class, 'responsesToLabel']);
            Route::post('/{id}/responses/to-leads', [SurveyFormController::class, 'responsesToLeads']);
        });



        // ── HR — Attendance / Breaks / Leave (mobile app + web) ──────────────
        Route::prefix('hr')->middleware(['company.active'])->group(function () {
            // Self service
            Route::get('attendance/me',          [AttendanceController::class, 'me']);
            Route::get('attendance/me/history',  [AttendanceController::class, 'myHistory']);
            Route::post('attendance/clock-in',   [AttendanceController::class, 'clockIn']);
            Route::post('attendance/clock-out',  [AttendanceController::class, 'clockOut']);
            Route::post('attendance/break/start', [AttendanceController::class, 'breakStart']);
            Route::post('attendance/break/end',  [AttendanceController::class, 'breakEnd']);

            // Team / admin (permission checked in the controller)
            Route::get('attendance',             [AttendanceController::class, 'index']);
            Route::get('attendance/payroll',     [AttendanceController::class, 'payroll']);
            Route::post('attendance',            [AttendanceController::class, 'storeEntry']);
            Route::patch('attendance/{id}',      [AttendanceController::class, 'update']);
            Route::post('attendance/{id}/overtime', [AttendanceController::class, 'reviewOvertime']);

            // Incentives
            Route::get('incentive-rules',        [IncentiveController::class, 'rules']);
            Route::post('incentive-rules',       [IncentiveController::class, 'storeRule']);
            Route::patch('incentive-rules/{id}', [IncentiveController::class, 'updateRule']);
            Route::delete('incentive-rules/{id}', [IncentiveController::class, 'destroyRule']);
            Route::get('incentives',             [IncentiveController::class, 'index']);
            Route::post('incentives',            [IncentiveController::class, 'store']);
            Route::patch('incentives/{id}',      [IncentiveController::class, 'update']);
            Route::delete('incentives/{id}',     [IncentiveController::class, 'destroy']);

            // Sales — catalog item sold / admission taken → auto-posts an incentive
            Route::get('sales',          [\App\Modules\Hr\Http\Controllers\SalesController::class, 'index']);
            Route::get('sales/summary',  [\App\Modules\Hr\Http\Controllers\SalesController::class, 'summary']);
            Route::post('sales',         [\App\Modules\Hr\Http\Controllers\SalesController::class, 'store']);
            Route::delete('sales/{id}',  [\App\Modules\Hr\Http\Controllers\SalesController::class, 'destroy']);

            // Payroll
            Route::get('payroll/runs',              [PayrollController::class, 'runs']);
            Route::post('payroll/runs',             [PayrollController::class, 'generate']);
            Route::get('payroll/runs/{id}',         [PayrollController::class, 'show']);
            Route::get('payroll/runs/{id}/report',  [PayrollController::class, 'report']);
            Route::post('payroll/runs/{id}/release', [PayrollController::class, 'release']);
            Route::patch('payroll/items/{id}',      [PayrollController::class, 'updateItem']);

            // Leave
            Route::get('leave/types',   [LeaveController::class, 'types']);
            Route::get('leave',         [LeaveController::class, 'index']);
            Route::post('leave',        [LeaveController::class, 'store']);
            Route::post('leave/{id}/cancel', [LeaveController::class, 'cancel']);
            Route::post('leave/{id}/review', [LeaveController::class, 'review']);

            // Config
            Route::get('settings',      [HrConfigController::class, 'showSettings']);
            Route::put('settings',      [HrConfigController::class, 'updateSettings']);
            Route::get('break-types',   [HrConfigController::class, 'breakTypes']);
            Route::post('break-types',  [HrConfigController::class, 'storeBreakType']);
            Route::patch('break-types/{id}',  [HrConfigController::class, 'updateBreakType']);
            Route::delete('break-types/{id}', [HrConfigController::class, 'destroyBreakType']);
            Route::get('leave-types',   [HrConfigController::class, 'leaveTypes']);
            Route::post('leave-types',  [HrConfigController::class, 'storeLeaveType']);
            Route::patch('leave-types/{id}',  [HrConfigController::class, 'updateLeaveType']);
            Route::delete('leave-types/{id}', [HrConfigController::class, 'destroyLeaveType']);
            Route::get('staff-profiles',          [HrConfigController::class, 'staffProfiles']);
            Route::put('staff-profiles/{userId}', [HrConfigController::class, 'updateStaffProfile']);
        });

        // ── Advanced CRM — Deals / Tasks / Segments ──────────────────────────
        Route::prefix('crm')->middleware(['company.active'])->group(function () {
            Route::get('deals/board',        [DealController::class, 'board']);
            Route::get('deals',              [DealController::class, 'index']);
            Route::post('deals',             [DealController::class, 'store']);
            Route::get('deals/{id}',         [DealController::class, 'show']);
            Route::patch('deals/{id}',       [DealController::class, 'update']);
            Route::patch('deals/{id}/stage', [DealController::class, 'moveStage']);
            Route::delete('deals/{id}',      [DealController::class, 'destroy']);

            Route::get('tasks',              [TaskController::class, 'index']);
            Route::post('tasks',             [TaskController::class, 'store']);
            Route::patch('tasks/{id}',       [TaskController::class, 'update']);
            Route::post('tasks/{id}/toggle', [TaskController::class, 'toggle']);
            Route::delete('tasks/{id}',      [TaskController::class, 'destroy']);

            Route::get('segments',           [SegmentController::class, 'index']);
            Route::post('segments',          [SegmentController::class, 'store']);
            Route::post('segments/preview',  [SegmentController::class, 'preview']);
            Route::patch('segments/{id}',    [SegmentController::class, 'update']);
            Route::delete('segments/{id}',   [SegmentController::class, 'destroy']);
        });

        Route::prefix('leads')->name('leads.')->group(function () {

            Route::get('/',           [LeadController::class, 'index'])->name('index');
            Route::get('/analytics',  [LeadController::class, 'analytics'])->name('analytics');
            Route::get('/logs',       [LeadController::class, 'logs'])->name('logs');
            Route::get('/sources',    [LeadController::class, 'sources'])->name('sources');
            Route::post('/import', [LeadController::class, 'import'])->middleware('permission:leads.create');
            Route::get('/export',  [LeadController::class, 'export'])->middleware('permission:leads.view_all');

            // Leads → Summary (Basic) + Report (Advanced). Declared before /{lead}.
            Route::get('/summary', [LeadReportController::class, 'summary'])->name('summary');
            Route::get('/report',  [LeadReportController::class, 'report'])->name('report');
            Route::get('/saved-reports',        [LeadReportController::class, 'savedReports']);
            Route::post('/saved-reports',       [LeadReportController::class, 'storeSavedReport']);
            Route::patch('/saved-reports/{id}', [LeadReportController::class, 'updateSavedReport']);
            Route::delete('/saved-reports/{id}',[LeadReportController::class, 'destroySavedReport']);

            Route::get('/{lead}',     [LeadController::class, 'show'])->name('show');

            Route::post('/', [LeadController::class, 'store'])->middleware('permission:leads.create')->name('store');

            Route::put('/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.edit')->name('update');

            Route::post('/{lead}/assign', [LeadController::class, 'assign'])->middleware('permission:leads.assign')->name('assign');

            Route::post('/bulk-assign', [LeadController::class, 'bulkAssign'])->middleware('permission:leads.assign')->name('bulk-assign');
            Route::post('/bulk-reassign', [LeadController::class, 'bulkReassign'])->middleware('permission:leads.assign')->name('bulk-reassign');

            Route::post('/{lead}/crm-sync', [LeadController::class, 'crmSync'])->middleware('permission:crm.sync')->name('crm-sync');

            Route::delete('/{lead}', [LeadController::class, 'destroy'])->middleware('permission:leads.delete')->name('destroy');
            Route::post('/bulk-delete', [LeadController::class, 'bulkDelete'])->middleware('permission:leads.delete')->name('bulk-delete');

            Route::get('/{lead}/notes',    [LeadNoteController::class, 'index'])->name('notes.index');
            // Notes
            Route::get('/{lead}/events',    [LeadNoteController::class, 'events'])->name('events.index');
            Route::post('/{lead}/notes',   [LeadNoteController::class, 'store'])->name('notes.store');
            Route::delete('/{lead}/notes/{event}', [LeadNoteController::class, 'destroy'])->name('notes.destroy');
        });


        // ── Lead Assignments ─────────────────────────────────────────────────────
        Route::prefix('lead-assignments')->name('lead-assignments.')->middleware('permission:leads.view')->group(function () {
            Route::get('/',          [LeadAssignmentController::class, 'index'])->name('index');
            Route::get('/stats',     [LeadAssignmentController::class, 'stats'])->name('stats');
            Route::get('/{id}',      [LeadAssignmentController::class, 'show'])->name('show');
            Route::post('/',         [LeadAssignmentController::class, 'store'])->name('store');
            Route::put('/{id}',      [LeadAssignmentController::class, 'update'])->name('update');
            Route::post('/{id}/accept',   [LeadAssignmentController::class, 'accept'])->name('accept');
            Route::post('/{id}/decline',  [LeadAssignmentController::class, 'decline'])->name('decline');
            Route::post('/{id}/complete', [LeadAssignmentController::class, 'complete'])->name('complete');
            Route::post('/{id}/transfer', [LeadAssignmentController::class, 'transfer'])->name('transfer');
        });

        Route::prefix('lead-assignment-rules')->name('lead-assignment-rules.')->group(function () {
            Route::get('/',  [LeadAssignmentController::class, 'getRule'])->name('show');
            Route::post('/', [LeadAssignmentController::class, 'saveRule'])->name('save');
        });

        // Per-weekday working hours + holiday overrides (used by the assignment engine)
        Route::prefix('working-hours')->group(function () {
            Route::get('/',  [WorkingHoursController::class, 'index']);
            Route::put('/',  [WorkingHoursController::class, 'update']);
            Route::post('/holidays',       [WorkingHoursController::class, 'storeHoliday']);
            Route::delete('/holidays/{id}', [WorkingHoursController::class, 'destroyHoliday']);
        });

        Route::prefix('staff')->name('staff.availability.')->group(function () {
            Route::get('/availability',        [LeadAssignmentController::class, 'staffAvailability'])->name('index');
            Route::post('/availability/toggle', [LeadAssignmentController::class, 'toggleAvailability'])->name('toggle');
        });

        Route::prefix('blacklist')->name('blacklist.')->group(function () {
            Route::get('/',        [BlacklistController::class, 'index'])->name('index');
            Route::post('/',       [BlacklistController::class, 'store'])->name('store');
            Route::post('/import', [BlacklistController::class, 'import'])->name('import');
            Route::delete('/{id}', [BlacklistController::class, 'destroy'])->name('destroy');
            Route::get('/check',   [BlacklistController::class, 'check'])->name('check');
        });


        // ── Labels ───────────────────────────────────────────────────────────────
        Route::prefix('labels')->name('labels.')->group(function () {

            // Read is open to any authenticated user: label pickers (e.g. the WhatsApp chat
            // contact panel) need the list without granting a dedicated labels.view permission.
            Route::get('/',        [LabelController::class, 'index'])->name('index');
            Route::get('/{label}', [LabelController::class, 'show'])->name('show');

            Route::middleware('permission:labels.manage')->group(function () {
                Route::post('/',           [LabelController::class, 'store'])->name('store');
                Route::put('/{label}',     [LabelController::class, 'update'])->name('update');
                Route::delete('/{label}',  [LabelController::class, 'destroy'])->name('destroy');
            });
        });

        // ── Contacts ─────────────────────────────────────────────────────────────
        Route::prefix('contacts')->name('contacts.')->group(function () {

            Route::middleware('permission:contacts.view')->group(function () {
                Route::get('/',               [ContactController::class, 'index'])->name('index');
                Route::get('/export',         [ContactController::class, 'export'])->name('export');
                Route::post('/by-labels',     [ContactController::class, 'byLabels'])->name('by-labels');
                Route::get('/{contact}',           [ContactController::class, 'show'])->name('show');
                Route::get('/{contact}/leads',     [ContactController::class, 'leads'])->name('leads');
                Route::get('/{contact}/campaigns', [ContactController::class, 'campaigns'])->name('campaigns');
            });

            Route::post('/import', [ContactController::class, 'import'])
                ->middleware('permission:contacts.import')
                ->name('import');

            Route::post('/', [ContactController::class, 'store'])
                ->middleware('permission:contacts.create')
                ->name('store');

            Route::put('/{contact}', [ContactController::class, 'update'])
                ->middleware('permission:contacts.edit')
                ->name('update');

            Route::patch('/{contact}/opt-out', [ContactController::class, 'optOut'])
                ->middleware('permission:contacts.edit')
                ->name('opt-out');

            Route::patch('/{contact}/opt-in', [ContactController::class, 'optIn'])
                ->middleware('permission:contacts.edit')
                ->name('opt-in');

            // Label assignment is a lightweight tagging action, not a contact edit: any authenticated
            // user who can see a contact (e.g. from the WhatsApp chat panel) may tag it.
            Route::post('/{contact}/labels', [ContactController::class, 'syncLabels'])
                ->name('sync-labels');

            Route::delete('/{contact}/labels/{label}', [ContactController::class, 'removeLabel'])
                ->name('remove-labels');

            Route::delete('/{contact}', [ContactController::class, 'destroy'])
                ->middleware('permission:contacts.delete')
                ->name('destroy');
        });


        Route::prefix('phone-numbers')->name('phone-numbers.')
            ->middleware(['company.active'])
            ->group(function () {
                Route::get('/',           [PhoneNumberController::class, 'index'])->name('index');
                Route::post('/',          [PhoneNumberController::class, 'store'])->name('store')->middleware('plan.limit:phone_numbers');
                Route::put('/{id}',       [PhoneNumberController::class, 'update'])->name('update');
                Route::delete('/{id}',    [PhoneNumberController::class, 'destroy'])->name('destroy');
                Route::post('/{id}/set-default', [PhoneNumberController::class, 'setDefault'])->name('set-default');
                Route::post('/{id}/verify',      [PhoneNumberController::class, 'verify'])->name('verify');
            });

        Route::prefix('analytics')->name('analytics.')->middleware(['company.active'])->group(function () {
            Route::get('overview',  [AnalyticsController::class, 'overview'])->name('overview');
            Route::get('campaigns', [AnalyticsController::class, 'campaigns'])->name('campaigns');
            Route::get('flows',     [AnalyticsController::class, 'flows'])->name('flows');
            Route::get('staff',     [AnalyticsController::class, 'staff'])->name('staff');
            Route::get('wallet',    [AnalyticsController::class, 'wallet'])->name('wallet');
            Route::get('leads',     [AnalyticsController::class, 'leads'])->name('leads');
            Route::get('messages',  [AnalyticsController::class, 'messages'])->name('messages');
            Route::get('cohort',        [AdvancedAnalyticsController::class, 'cohort']);
            Route::get('staff-compare', [AdvancedAnalyticsController::class, 'staffCompare']);
            Route::get('send-time',     [AdvancedAnalyticsController::class, 'sendTime']);
            Route::get('flow-nodes',    [AdvancedAnalyticsController::class, 'flowNodes']);
            Route::get('campaigns',     [AdvancedAnalyticsController::class, 'campaignTrends']);
            Route::get('burn-rate',     [AdvancedAnalyticsController::class, 'burnRate']);
            Route::get('top-leads',     [AdvancedAnalyticsController::class, 'topLeads']);
        });


        // Route::prefix('flow')->name('flow.')->group(function () {

        //     // ── Read ─────────────────────────────────────────────────────────────
        //     Route::middleware('permission:flow.view')->group(function () {
        //         Route::get('/',            [FlowController::class, 'tree'])->name('tree');
        //         Route::get('/flat',        [FlowController::class, 'flat'])->name('flat');
        //         Route::get('/{node}',      [FlowController::class, 'show'])->name('show');
        //         Route::get('/analytics',   [FlowAnalyticsController::class, 'index'])->name('analytics');
        //     });

        //     // ── Manage ───────────────────────────────────────────────────────────
        //     Route::middleware('permission:flow.manage')->group(function () {
        //         Route::post('/',              [FlowController::class, 'store'])->name('store');
        //         Route::put('/{node}',         [FlowController::class, 'update'])->name('update');
        //         Route::delete('/{node}',      [FlowController::class, 'destroy'])->name('destroy');
        //         Route::post('/reorder',       [FlowController::class, 'reorder'])->name('reorder');
        //         Route::patch('/{node}/toggle', [FlowController::class, 'toggle'])->name('toggle');
        //         Route::post('/duplicate/{node}', [FlowController::class, 'duplicate'])->name('duplicate');
        //     });
        // });

        Route::prefix('wallet')->name('wallet.')->group(function () {

            // Overview + transactions
            Route::get('/',             [WalletController::class, 'index'])->name('index');
            Route::get('/transactions', [WalletController::class, 'transactions'])->name('transactions');
            Route::get('/packages',     [WalletController::class, 'packages'])->name('packages');

            // Settings (owner/admin)
            Route::put('/settings', [WalletController::class, 'updateSettings'])
                ->middleware('permission:billing.manage')
                ->name('settings');

            // Razorpay: create order + verify
            Route::post('/create-order',   [PaymentController::class, 'createOrder'])->name('create-order');
            Route::post('/verify-payment', [PaymentController::class, 'verifyPayment'])->name('verify-payment');
        });


        //     // ── Message Logs ─────────────────────────────────────────────────────────
        Route::get('message-logs', [MessageLogController::class, 'index'])->name('message-logs.index');
        Route::get('message-logs/{log}', [MessageLogController::class, 'show'])->name('message-logs.show');


        // Authenticated company routes
        Route::middleware(['company.active'])->group(function () {
            Route::get('plans',                        [PlanPurchaseController::class, 'index'])->name('plans.index');
            Route::get('plans/current',               [PlanPurchaseController::class, 'currentPlan'])->name('plans.current');
            Route::post('plans/preview-change',        [PlanPurchaseController::class, 'previewChange'])->name('plans.preview-change');
            Route::post('plans/create-order',          [PlanPurchaseController::class, 'createOrder'])->name('plans.create-order');
            Route::post('plans/verify-payment',        [PlanPurchaseController::class, 'verifyPayment'])->name('plans.verify-payment');
            Route::get('plans/history',                [PlanPurchaseController::class, 'history'])->name('plans.history');
            Route::get('addons',                       [PlanPurchaseController::class, 'addons'])->name('addons.index');
            Route::post('addons/{addon}/create-order', [PlanPurchaseController::class, 'addonOrder'])->name('addons.order');
            Route::post('addons/verify-payment',       [PlanPurchaseController::class, 'verifyAddonPayment'])->name('addons.verify');
        });

        // Superadmin plan management
        Route::middleware(['superadmin'])->prefix('superadmin')->group(function () {
            Route::get('plans',             [PlanPurchaseController::class, 'superAdminPlans'])->name('sa.plans.index');
            Route::post('plans',            [PlanPurchaseController::class, 'superAdminCreatePlan'])->name('sa.plans.store');
            Route::put('plans/{id}',        [PlanPurchaseController::class, 'superAdminUpdatePlan'])->name('sa.plans.update');
            Route::delete('plans/{id}',     [PlanPurchaseController::class, 'superAdminDeletePlan'])->name('sa.plans.destroy');
            Route::post('plans/assign',     [PlanPurchaseController::class, 'assignCustomPlan'])->name('sa.plans.assign');
            Route::get('addons',            [PlanPurchaseController::class, 'superAdminAddons'])->name('sa.addons.index');
            Route::post('addons',           [PlanPurchaseController::class, 'superAdminCreateAddon'])->name('sa.addons.store');
            Route::put('addons/{id}',       [PlanPurchaseController::class, 'superAdminUpdateAddon'])->name('sa.addons.update');
            Route::get('topup-packages',    [PlanPurchaseController::class, 'topupPackages'])->name('sa.topup.index');
            Route::post('topup-packages',   [PlanPurchaseController::class, 'createTopupPackage'])->name('sa.topup.store');
            Route::put('topup-packages/{id}', [PlanPurchaseController::class, 'updateTopupPackage'])->name('sa.topup.update');
            Route::delete('topup-packages/{id}', [PlanPurchaseController::class, 'deleteTopupPackage'])->name('sa.topup.destroy');
        });

        Route::prefix('templates')->name('templates.')
            ->middleware(['company.active'])
            ->group(function () {
                Route::get('/',                 [TemplateController::class, 'index'])->name('index');

                // Bulk sync must come before '/{id}' so it isn't swallowed by the id route
                Route::post('/sync-from-meta',  [TemplateController::class, 'syncFromMeta'])->name('sync-from-meta');
                Route::post('{id}/duplicate', [TemplateController::class, 'duplicate'])->name('duplicate')->middleware('plan.limit:templates');
                Route::get('/{id}',             [TemplateController::class, 'show'])->name('show');
                Route::post('/',                [TemplateController::class, 'store'])->name('store')->middleware('plan.limit:templates');
                Route::put('/{id}',             [TemplateController::class, 'update'])->name('update');
                Route::delete('/{id}',          [TemplateController::class, 'destroy'])->name('destroy');

                // Per-template single sync (pulls latest status for just this one template)
                Route::post('/{id}/sync',       [TemplateController::class, 'syncSingle'])->name('sync');

                // Draft → submit to Meta, once all required media is attached
                Route::post('/{id}/submit',     [TemplateController::class, 'submit'])->name('submit');

                // Header media
                Route::post('/{id}/upload-header-media',   [TemplateController::class, 'uploadHeaderMedia'])->name('upload-header-media');
                Route::delete('/{id}/delete-header-media', [TemplateController::class, 'deleteHeaderMedia'])->name('delete-header-media');

                // Footer media — stored locally only, never sent to Meta (see migration note)
                Route::post('/{id}/upload-footer-media',   [TemplateController::class, 'uploadFooterMedia'])->name('upload-footer-media');
                Route::delete('/{id}/delete-footer-media', [TemplateController::class, 'deleteFooterMedia'])->name('delete-footer-media');

                // Per-button media — stored locally only, never sent to Meta (buttons are text-only in the Graph API)
                Route::post('/{id}/buttons/{buttonId}/upload-media',   [TemplateController::class, 'uploadButtonMedia'])->name('upload-button-media');
                Route::delete('/{id}/buttons/{buttonId}/delete-media', [TemplateController::class, 'deleteButtonMedia'])->name('delete-button-media');
            });

        // Route::get('flow-builders',             [FlowBuilderController::class, 'index']);
        // Route::post('flow-builders',             [FlowBuilderController::class, 'store']);
        // Route::put('flow-builders/{id}',        [FlowBuilderController::class, 'update']);
        // Route::delete('flow-builders/{id}',        [FlowBuilderController::class, 'destroy']);
        // Route::post('flow-builders/{id}/activate', [FlowBuilderController::class, 'activate']);





        // Flow Builders
        Route::get('flow-builders',                 [FlowBuilderController::class, 'index']);
        Route::post('flow-builders',                 [FlowBuilderController::class, 'store']);
        Route::post('flow-builders/import',      [FlowImportExportController::class, 'import']);
        Route::get('flow-builders/{id}/export', [FlowImportExportController::class, 'export']);
        Route::get('flow-builders/{id}',            [FlowBuilderController::class, 'show']);
        Route::put('flow-builders/{id}',            [FlowBuilderController::class, 'update']);
        Route::delete('flow-builders/{id}',            [FlowBuilderController::class, 'destroy']);
        Route::post('flow-builders/{id}/activate',   [FlowBuilderController::class, 'activate']);
        Route::post('flow-builders/{id}/deactivate', [FlowBuilderController::class, 'deactivate']);

        // Flow Nodes (nested under builder)
        Route::get('flow-builders/{bid}/nodes',            [FlowNodeController::class, 'index']);
        Route::post('flow-builders/{bid}/nodes',            [FlowNodeController::class, 'store']);
        Route::put('flow-builders/{bid}/nodes/{id}',       [FlowNodeController::class, 'update']);
        Route::delete('flow-builders/{bid}/nodes/{id}',       [FlowNodeController::class, 'destroy']);
        Route::post('flow-builders/{bid}/nodes/{id}/toggle', [FlowNodeController::class, 'toggle']);
        Route::post('flow-builders/{bid}/nodes/{id}/activate', [FlowNodeController::class, 'activate']);
        Route::post('flow-builders/{bid}/nodes/{id}/deactivate', [FlowNodeController::class, 'deactivate']);
        Route::post('flow-builders/{bid}/nodes/{id}/move', [FlowNodeController::class, 'move']);


        Route::post('flow-builders/{bid}/nodes/reorder',    [FlowNodeController::class, 'reorder']);
        Route::get('flow-builders/{builder}/nodes/check-reply-id', [FlowNodeController::class, 'checkReplyId']);
        Route::post('flow-builders/{builder}/nodes/upload-media', [FlowNodeController::class, 'uploadMedia']);

        // ── Flow Builders ───────────────────────────────────────────────────────────
        // Route::get('flow-builders',                 [FlowBuilderController::class, 'index']);
        // Route::post('flow-builders',                 [FlowBuilderController::class, 'store']);
        // Route::get('flow-builders/{id}',            [FlowBuilderController::class, 'show']);
        // Route::put('flow-builders/{id}',            [FlowBuilderController::class, 'update']);
        // Route::delete('flow-builders/{id}',            [FlowBuilderController::class, 'destroy']);
        // Route::post('flow-builders/{id}/activate',   [FlowBuilderController::class, 'activate']);
        // Route::post('flow-builders/{id}/deactivate', [FlowBuilderController::class, 'deactivate']);
        // Route::post('flow-builders/{id}/duplicate',  [FlowBuilderController::class, 'duplicate']);

        // // ── Flow Nodes (nested under a builder) ─────────────────────────────────────
        // Route::get('flow-builders/{builderId}/nodes',                 [FlowNodeController::class, 'index']);
        // Route::post('flow-builders/{builderId}/nodes',                 [FlowNodeController::class, 'store']);
        // Route::put('flow-builders/{builderId}/nodes/{id}',            [FlowNodeController::class, 'update']);
        // Route::delete('flow-builders/{builderId}/nodes/{id}',            [FlowNodeController::class, 'destroy']);
        // Route::post('flow-builders/{builderId}/nodes/{id}/activate',   [FlowNodeController::class, 'activate']);
        // Route::post('flow-builders/{builderId}/nodes/{id}/deactivate', [FlowNodeController::class, 'deactivate']);
        // Route::post('flow-builders/{builderId}/nodes/reorder',         [FlowNodeController::class, 'reorder']);



        Route::get('company-roles',      [CompanyRoleController::class, 'index']);
        Route::post('company-roles',      [CompanyRoleController::class, 'store']);
        Route::put('company-roles/{id}', [CompanyRoleController::class, 'update']);



        Route::prefix('/meta-ads')->name('meta-ads.')->middleware(['company.active'])->group(function () {

            // Ad accounts
            Route::get('accounts',                  [MetaAdAccountController::class, 'index']);
            Route::post('accounts',                 [MetaAdAccountController::class, 'store']);
            Route::put('accounts/{id}',             [MetaAdAccountController::class, 'update']);
            Route::delete('accounts/{id}',          [MetaAdAccountController::class, 'destroy']);
            Route::post('accounts/{id}/set-default', [MetaAdAccountController::class, 'setDefault']);
            Route::get('accounts/{id}/verify',      [MetaAdAccountController::class, 'verify']);

            // Audience templates (system starter presets)
            Route::get('audience-templates',        [MetaAdSetController::class, 'audienceTemplates']);

            // Audience sets — company-owned, reusable, one-click apply / customize
            Route::get('audience-sets',                     [MetaAudienceSetController::class, 'index']);
            Route::post('audience-sets',                    [MetaAudienceSetController::class, 'store']);
            Route::post('audience-sets/estimate-reach',     [MetaAudienceSetController::class, 'estimateReach']);
            Route::get('audience-sets/targeting-search',    [MetaAudienceSetController::class, 'search']);
            Route::post('audience-sets/from-template/{templateId}', [MetaAudienceSetController::class, 'fromTemplate']);
            Route::post('audience-sets/from-adset/{adSetId}',       [MetaAudienceSetController::class, 'fromAdSet']);
            Route::get('audience-sets/{id}',                [MetaAudienceSetController::class, 'show']);
            Route::put('audience-sets/{id}',                [MetaAudienceSetController::class, 'update']);
            Route::post('audience-sets/{id}/duplicate',     [MetaAudienceSetController::class, 'duplicate']);
            Route::delete('audience-sets/{id}',             [MetaAudienceSetController::class, 'destroy']);

            // Campaigns
            Route::get('campaigns',                 [MetaCampaignController::class, 'index']);
            Route::post('campaigns',                [MetaCampaignController::class, 'store']);
            Route::get('campaigns/{id}',            [MetaCampaignController::class, 'show']);
            Route::put('campaigns/{id}',            [MetaCampaignController::class, 'update']);
            Route::delete('campaigns/{id}',         [MetaCampaignController::class, 'destroy']);
            Route::patch('campaigns/{id}/status',   [MetaCampaignController::class, 'updateStatus']);
            Route::post('campaigns/{id}/duplicate', [MetaCampaignController::class, 'duplicate']);

            // Ad sets
            Route::get('campaigns/{cid}/adsets',    [MetaAdSetController::class, 'index']);
            Route::post('campaigns/{cid}/adsets',   [MetaAdSetController::class, 'store']);
            Route::put('adsets/{id}',               [MetaAdSetController::class, 'update']);
            Route::patch('adsets/{id}/status',      [MetaAdSetController::class, 'updateStatus']);
            Route::post('adsets/{id}/duplicate',    [MetaAdSetController::class, 'duplicate']);
            Route::delete('adsets/{id}',            [MetaAdSetController::class, 'destroy']);

            // Media library
            Route::get('media',                     [MetaMediaController::class, 'index']);
            Route::post('media/upload-image',       [MetaMediaController::class, 'uploadImage']);
            Route::post('media/upload-video',       [MetaMediaController::class, 'uploadVideo']);
            Route::delete('media/{id}',             [MetaMediaController::class, 'destroy']);

            // Creatives
            Route::get('creatives',                 [MetaCreativeController::class, 'index']);
            Route::post('creatives',                [MetaCreativeController::class, 'store']);
            Route::get('creatives/{id}',            [MetaCreativeController::class, 'show']);
            Route::delete('creatives/{id}',         [MetaCreativeController::class, 'destroy']);

            // Ads
            Route::get('adsets/{sid}/ads',          [MetaAdController::class, 'index']);
            Route::post('adsets/{sid}/ads',         [MetaAdController::class, 'store']);
            Route::patch('ads/{id}/status',         [MetaAdController::class, 'updateStatus']);
            Route::post('ads/{id}/sync-review',     [MetaAdController::class, 'syncReview']);
            Route::delete('ads/{id}',               [MetaAdController::class, 'destroy']);

            // Insights
            Route::get('insights/campaign/{id}',    [MetaInsightController::class, 'campaign']);
            Route::get('insights/overview',         [MetaInsightController::class, 'overview']);
            Route::post('insights/sync/{campaignId}', [MetaInsightController::class, 'sync']);

            // AI campaign builder
            Route::get('ai/status',                   [MetaAdsAiController::class, 'status']);
            Route::post('ai/plan',                    [MetaAdsAiController::class, 'plan']);
            Route::post('ai/build',                   [MetaAdsAiController::class, 'build']);

            // MCP server — JSON-RPC endpoint an AI agent connects to
            Route::post('mcp',                        [McpController::class, 'handle']);

            // Lead ads → CRM
            Route::get('lead-forms',                  [MetaLeadController::class, 'forms']);
            Route::post('lead-forms',                 [MetaLeadController::class, 'createForm']);
            Route::post('lead-forms/sync',            [MetaLeadController::class, 'syncForms']);
            Route::post('lead-forms/{formId}/sync-leads', [MetaLeadController::class, 'syncFormLeads']);
            Route::get('leads',                       [MetaLeadController::class, 'leads']);
        });

        // ── Google Sheets / Drive lead sync ──
        Route::prefix('/google')->middleware(['company.active'])->group(function () {
            Route::get('/connect',       [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'connectUrl']);
            Route::get('/status',        [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'status']);
            Route::delete('/disconnect', [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'disconnect']);
            Route::post('/syncs',        [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'createSync']);
            Route::patch('/syncs/{id}',  [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'updateSync']);
            Route::post('/syncs/{id}/run', [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'syncNow']);
            Route::delete('/syncs/{id}', [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'deleteSync']);
            Route::get('/drive/files',    [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'driveFiles']);
            Route::post('/drive/upload',  [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'driveUpload']);
            Route::post('/drive/folders', [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'driveCreateFolder']);
            Route::patch('/drive/files/{fileId}',  [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'driveRename']);
            Route::delete('/drive/files/{fileId}', [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'driveDelete']);
        });

        // ── Website chat widget management ──
        Route::prefix('/widgets')->middleware(['company.active'])->group(function () {
            Route::get('/',        [\App\Modules\Catalog\Http\Controllers\WidgetController::class, 'index']);
            Route::post('/',       [\App\Modules\Catalog\Http\Controllers\WidgetController::class, 'store']);
            Route::put('/{id}',    [\App\Modules\Catalog\Http\Controllers\WidgetController::class, 'update']);
            Route::delete('/{id}', [\App\Modules\Catalog\Http\Controllers\WidgetController::class, 'destroy']);
            Route::get('/{id}/conversations',       [\App\Modules\Catalog\Http\Controllers\WidgetController::class, 'conversations']);
            Route::get('/{id}/conversations/{cid}', [\App\Modules\Catalog\Http\Controllers\WidgetController::class, 'conversation']);
        });

        // ── Catalog: listings the AI agent answers about + matches leads against ──
        Route::prefix('/listings')->middleware(['company.active'])->group(function () {
            Route::get('/templates',       [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'templates']);
            Route::post('/templates',      [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'setTemplate']);
            Route::post('/match',          [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'match']);
            Route::get('/export',          [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'export']);
            Route::post('/import',         [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'import']);
            Route::get('/',                [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'index']);
            Route::post('/',               [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'store']);
            Route::get('/{id}',            [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'show']);
            Route::put('/{id}',            [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'update']);
            Route::delete('/{id}',         [\App\Modules\Catalog\Http\Controllers\ListingController::class, 'destroy']);
        });

        // ── Instagram: connected account, keyword auto-DM bot, DM inbox + AI agent ──
        Route::prefix('/instagram')->name('instagram.')->middleware(['company.active'])->group(function () {
            Route::post('accounts/discover-pages', [\App\Modules\Instagram\Http\Controllers\InstagramAccountController::class, 'discoverPages']);
            Route::get('accounts',                 [\App\Modules\Instagram\Http\Controllers\InstagramAccountController::class, 'index']);
            Route::post('accounts',                [\App\Modules\Instagram\Http\Controllers\InstagramAccountController::class, 'store']);
            Route::put('accounts/{id}',            [\App\Modules\Instagram\Http\Controllers\InstagramAccountController::class, 'update']);
            Route::delete('accounts/{id}',         [\App\Modules\Instagram\Http\Controllers\InstagramAccountController::class, 'destroy']);
            Route::post('accounts/{id}/sync',      [\App\Modules\Instagram\Http\Controllers\InstagramAccountController::class, 'sync']);
            Route::get('accounts/{id}/media',      [\App\Modules\Instagram\Http\Controllers\InstagramAccountController::class, 'media']);
            Route::get('accounts/{id}/insights',   [\App\Modules\Instagram\Http\Controllers\InstagramCommentController::class, 'insights']);
            Route::post('accounts/{id}/import-listings', [\App\Modules\Instagram\Http\Controllers\InstagramAccountController::class, 'importListings']);

            // Comment moderation: read, reply to, hide and delete comments on a post/reel.
            Route::get('accounts/{id}/media/{mediaId}/comments',      [\App\Modules\Instagram\Http\Controllers\InstagramCommentController::class, 'index']);
            Route::post('accounts/{id}/comments/{commentId}/reply',   [\App\Modules\Instagram\Http\Controllers\InstagramCommentController::class, 'reply']);
            Route::post('accounts/{id}/comments/{commentId}/hide',    [\App\Modules\Instagram\Http\Controllers\InstagramCommentController::class, 'hide']);
            Route::delete('accounts/{id}/comments/{commentId}',       [\App\Modules\Instagram\Http\Controllers\InstagramCommentController::class, 'destroy']);

            Route::get('automations',              [\App\Modules\Instagram\Http\Controllers\InstagramAutomationController::class, 'index']);
            Route::post('automations',             [\App\Modules\Instagram\Http\Controllers\InstagramAutomationController::class, 'store']);
            Route::put('automations/{id}',         [\App\Modules\Instagram\Http\Controllers\InstagramAutomationController::class, 'update']);
            Route::post('automations/{id}/toggle', [\App\Modules\Instagram\Http\Controllers\InstagramAutomationController::class, 'toggle']);
            Route::post('automations/{id}/test',   [\App\Modules\Instagram\Http\Controllers\InstagramAutomationController::class, 'test']);
            Route::delete('automations/{id}',      [\App\Modules\Instagram\Http\Controllers\InstagramAutomationController::class, 'destroy']);

            Route::get('conversations',            [\App\Modules\Instagram\Http\Controllers\InstagramInboxController::class, 'index']);
            Route::get('conversations/{id}',       [\App\Modules\Instagram\Http\Controllers\InstagramInboxController::class, 'show']);
            Route::post('conversations/{id}/reply', [\App\Modules\Instagram\Http\Controllers\InstagramInboxController::class, 'reply']);
            Route::patch('conversations/{id}',     [\App\Modules\Instagram\Http\Controllers\InstagramInboxController::class, 'updateStatus']);
        });


        Route::delete('company-roles/{id}', [CompanyRoleController::class, 'destroy']); // is_system check

        Route::prefix('push')->name('push.')
            ->middleware(['company.active'])
            ->group(function () {
                Route::post('/register-token',   [PushNotificationController::class, 'registerToken'])->middleware('jwt.auth');
                Route::delete('/unregister-token', [PushNotificationController::class, 'unregisterToken'])->middleware('jwt.auth');
                Route::get('/history',           [PushNotificationController::class, 'history'])->middleware('jwt.auth');
                //
            });


        Route::get('auth/profile',          [AuthController::class, 'profile']);
        Route::put('auth/profile',          [AuthController::class, 'updateProfile']);
        Route::post('auth/change-password',  [AuthController::class, 'changePassword']);
        Route::post('auth/forgot-password',  [AuthController::class, 'forgotPassword']);    // public
        Route::post('auth/reset-password',   [AuthController::class, 'resetPassword']);     // public
    });


    // Public — plan listing (companies can see plans before login)
    Route::get('plans/public', [PlanPurchaseController::class, 'publicPlans'])->name('plans.public');





    // Route::prefix('templates')->name('templates.')
    //     ->middleware(['company.active'])
    //     ->group(function () {
    //         Route::get('/',           [TemplateController::class, 'index'])->name('index');
    //         Route::get('/{id}',       [TemplateController::class, 'show'])->name('show');
    //         Route::post('/',          [TemplateController::class, 'store'])->name('store')->middleware('plan.limit:templates');
    //         Route::put('/{id}',       [TemplateController::class, 'update'])->name('update');
    //         Route::delete('/{id}',    [TemplateController::class, 'destroy'])->name('destroy');
    //         Route::post('/{id}/sync', [TemplateController::class, 'syncFromMeta'])->name('sync');
    //     });






    Route::prefix('webhook')->name('webhook.')->group(function () {
        // Meta verification challenge
        Route::get('whatsapp',  [WebhookController::class, 'verify'])->name('verify');
        // Inbound events from Meta
        Route::post('whatsapp', [WebhookController::class, 'handle'])->name('handle');



            Route::any('/dummy-whatsapp', function (Request $request) {
                Log::info($request->all());
            });

    });




    // ── Razorpay webhook (public — verified by signature) ────────────────────
    Route::post('razorpay/webhook', [PaymentController::class, 'webhook']);

    // Public webhook (Meta lead ads + ad review). GET = subscription verification handshake.
    Route::get('/meta-ads/webhook', [MetaWebhookController::class, 'verify']);
    Route::post('/meta-ads/webhook', [MetaWebhookController::class, 'handle']);

    // Public webhook — Instagram comments + DMs.
    Route::get('/instagram/webhook',  [\App\Modules\Instagram\Http\Controllers\InstagramWebhookController::class, 'verify']);
    Route::post('/instagram/webhook', [\App\Modules\Instagram\Http\Controllers\InstagramWebhookController::class, 'handle']);

    // Google OAuth redirect-back (browser hit, not an API call).
    Route::get('/google/callback', [\App\Modules\Google\Http\Controllers\GoogleIntegrationController::class, 'callback']);

    // ── Public website chat widget (called from customer sites) ──
    Route::prefix('public/widget')->middleware(['widget.cors'])->group(function () {
        Route::get('/{key}.js',        [\App\Modules\Catalog\Http\Controllers\WidgetPublicController::class, 'script']);
        Route::get('/{key}/bootstrap', [\App\Modules\Catalog\Http\Controllers\WidgetPublicController::class, 'bootstrap']);
        Route::match(['post', 'options'], '/{key}/chat', [\App\Modules\Catalog\Http\Controllers\WidgetPublicController::class, 'chat'])
            ->middleware('throttle:30,1');
    });
});



// Route::prefix('extension')->group(function () {
Route::post('/login', [ExtensionController::class, 'login']);
// Route::middleware('auth:api')->group(function () {
Route::get('/contacts', [ExtensionController::class, 'contacts']);
Route::get('/labels', [ExtensionController::class, 'labels']);
Route::get('/templates', [ExtensionController::class, 'templates']);
Route::post('/contacts-by-labels', [ExtensionController::class, 'contactsByLabels']);
Route::post('/send', [ExtensionController::class, 'send']); // calls Graph API, not DOM automation
        // });
    // });


// ═══════════════════════════════════════════════════════════════════════════
// WA Chat Module Routes (unichatwa)
// API_SEPARATION: these are Project B routes — unichatwa.univexa.in
// ═══════════════════════════════════════════════════════════════════════════

// ── Public WA Cloud Api Service ──────────────────────────────────────────────
// No path token — the Authorization: Bearer <api_token> header alone (matched
// against wa_cloud_otp_services.api_token, which is globally unique) identifies
// and authenticates the company's service. Declared BEFORE the wa-chat group
// below so the literal "wa-cloud" prefix wins over that group's {waChatToken}
// wildcard (its regex [A-Za-z0-9_\-]+ would otherwise also match "wa-cloud").
Route::prefix('v1/wa-cloud')->group(function () {
    Route::post('otp/send',                  [WaCloudApiPublicController::class, 'publicSend']);
    Route::post('otp/verify',                [WaCloudApiPublicController::class, 'publicVerify']);
    Route::post('otp/resend',                [WaCloudApiPublicController::class, 'publicResend']);
    Route::post('api-service/utility-send',  [WaCloudApiPublicController::class, 'publicUtilitySend']);
    Route::post('api-service/invoice-share', [WaCloudApiPublicController::class, 'publicInvoiceShare']);
});

// ── Public Api Service ───────────────────────────────────────────────────────
// The {waChatToken} path segment is the company's Company.wa_chat_token — it
// identifies the company. The Authorization: Bearer <api_token> header (checked
// against wa_otp_services.api_token for that company) authenticates the service.
Route::prefix('v1/{waChatToken}')
    ->where(['waChatToken' => '[A-Za-z0-9_\-]+'])
    ->group(function () {
        Route::post('otp/send',   [WaOtpPublicController::class, 'publicSend']);
        Route::post('otp/verify', [WaOtpPublicController::class, 'publicVerify']);
        Route::post('otp/resend', [WaOtpPublicController::class, 'publicResend']);
        Route::post('api-service/utility-send',  [WaOtpPublicController::class, 'publicUtilitySend']);
        Route::post('api-service/invoice-share', [WaOtpPublicController::class, 'publicInvoiceShare']);
    });

// ── WAHA session webhook receiver (public — called by WAHA server) ──
Route::post('v1/waha/webhook', [WahaSessionController::class, 'webhook']);

// ── Protected WA Chat routes ─────────────────────────────────────────────
Route::prefix('v1')->middleware(['jwt.auth'])->group(function () {

    // Gateway API-key status / reconnect
    Route::get('waha/token',            [WahaSessionController::class, 'tokenStatus']);
    Route::post('waha/token/reconnect', [WahaSessionController::class, 'reconnectToken'])
        ->middleware('permission:settings.manage');

    // Company-scoped WA Chat analytics (aggregates per-session gateway stats)
    Route::get('wa-chat/analytics', [WaChatAnalyticsController::class, 'index']);

    // Sessions
    Route::prefix('waha/sessions')->group(function () {
        Route::get('/',              [WahaSessionController::class, 'index']);
        Route::get('/health',        [WahaSessionController::class, 'health']);
        Route::post('/',             [WahaSessionController::class, 'store'])->middleware('plan.limit:wa_sessions');
        Route::get('/{id}',          [WahaSessionController::class, 'show']);
        Route::patch('/{id}',        [WahaSessionController::class, 'update']);
        Route::post('/{id}/start',   [WahaSessionController::class, 'start']);
        Route::post('/{id}/stop',    [WahaSessionController::class, 'stop']);
        Route::post('/{id}/logout',  [WahaSessionController::class, 'logout']);
        Route::get('/{id}/qr',       [WahaSessionController::class, 'qr']);
        Route::delete('/{id}',       [WahaSessionController::class, 'destroy']);
    });

    // WA Groups proxy (calls WAHA)
    Route::prefix('waha')->group(function () {
        Route::get('groups',                              [WahaSessionController::class, 'groups']);
        Route::post('groups',                             [WahaSessionController::class, 'createGroup']);
        Route::post('groups/join',                        [WahaSessionController::class, 'joinGroup']);
        Route::get('groups/join-info',                    [WahaSessionController::class, 'getJoinInfo']);

        Route::get('group/info',                          [WahaSessionController::class, 'groupInfo']);
        Route::get('group/participants',                  [WahaSessionController::class, 'groupParticipants']);
        Route::post('group/participants/add',             [WahaSessionController::class, 'addParticipants']);
        Route::delete('group/participants/remove',        [WahaSessionController::class, 'removeParticipants']);
        Route::post('group/participants/promote',         [WahaSessionController::class, 'promoteParticipants']);
        Route::post('group/participants/demote',          [WahaSessionController::class, 'demoteParticipants']);
        Route::put('group/subject',                       [WahaSessionController::class, 'updateGroupSubject']);
        Route::put('group/description',                   [WahaSessionController::class, 'updateGroupDescription']);
        Route::get('group/invite-code',                   [WahaSessionController::class, 'getGroupInviteCode']);
        Route::post('group/invite-code/revoke',           [WahaSessionController::class, 'revokeGroupInviteCode']);
        Route::post('group/leave',                        [WahaSessionController::class, 'leaveGroup']);
        Route::get('group/membership-requests',           [WahaSessionController::class, 'getMembershipRequests']);
        Route::post('group/membership-requests/approve',  [WahaSessionController::class, 'approveMembershipRequests']);
        Route::post('group/membership-requests/reject',   [WahaSessionController::class, 'rejectMembershipRequests']);
        Route::get('group/picture',                       [WahaSessionController::class, 'getGroupPicture']);
        Route::delete('group/picture',                    [WahaSessionController::class, 'deleteGroupPicture']);
        Route::get('group/settings',                      [WahaSessionController::class, 'getGroupSettings']);
        Route::put('group/settings',                      [WahaSessionController::class, 'updateGroupSettings']);
    });

    // Webhook configs
    Route::prefix('waha/webhooks')->group(function () {
        Route::get('/',          [WahaWebhookConfigController::class, 'index']);
        Route::post('/',         [WahaWebhookConfigController::class, 'store']);
        Route::patch('/{id}',    [WahaWebhookConfigController::class, 'update']);
        Route::delete('/{id}',   [WahaWebhookConfigController::class, 'destroy']);
        Route::post('/{id}/test',[WahaWebhookConfigController::class, 'test']);
    });

    // Message Sender
    Route::prefix('message-sender')->group(function () {
        Route::get('/',              [MessageSenderController::class, 'index']);
        Route::post('/',             [MessageSenderController::class, 'store']);
        Route::get('/stats',         [MessageSenderController::class, 'stats']);
        Route::get('/{id}',          [MessageSenderController::class, 'show']);
        Route::post('/{id}/launch',  [MessageSenderController::class, 'launch']);
        Route::post('/{id}/pause',   [MessageSenderController::class, 'pause']);
        Route::post('/{id}/resume',  [MessageSenderController::class, 'resume']);
        Route::post('/{id}/stop',    [MessageSenderController::class, 'stop']);
        Route::delete('/{id}',       [MessageSenderController::class, 'destroy']);
    });

    // Media Library
    Route::prefix('media-library')->group(function () {
        Route::get('/',               [MediaLibraryController::class, 'index']);
        Route::post('/upload',        [MediaLibraryController::class, 'upload']);
        Route::post('/bulk/move',     [MediaLibraryController::class, 'bulkMove']);
        Route::post('/bulk/copy',     [MediaLibraryController::class, 'bulkCopy']);
        Route::post('/bulk/delete',   [MediaLibraryController::class, 'bulkDestroy']);
        Route::patch('/{id}/rename',  [MediaLibraryController::class, 'rename']);
        Route::patch('/{id}/move',    [MediaLibraryController::class, 'move']);
        Route::post('/{id}/copy',     [MediaLibraryController::class, 'copy']);
        Route::delete('/{id}',        [MediaLibraryController::class, 'destroy']);
        // Folder management
        Route::get('/folders',        [MediaFolderController::class, 'index']);
        Route::post('/folders',       [MediaFolderController::class, 'store']);
        Route::patch('/folders/{id}', [MediaFolderController::class, 'update']);
        Route::delete('/folders/{id}',[MediaFolderController::class, 'destroy']);
    });

    // WA Chat Templates
    Route::prefix('wa-chat-templates')->group(function () {
        Route::get('/',        [WaChatTemplateController::class, 'index']);
        Route::post('/',       [WaChatTemplateController::class, 'store']);
        Route::get('/{id}',    [WaChatTemplateController::class, 'show']);
        Route::patch('/{id}',  [WaChatTemplateController::class, 'update']);
        Route::delete('/{id}', [WaChatTemplateController::class, 'destroy']);
    });

    // OTP Service (management — protected)
    Route::prefix('otp-service')->group(function () {
        Route::get('/',                      [WaOtpServiceController::class, 'show']);
        Route::post('/',                     [WaOtpServiceController::class, 'storeOrUpdate']);
        Route::post('/reset-token',          [WaOtpServiceController::class, 'resetToken']);
        Route::post('/stop-token',           [WaOtpServiceController::class, 'stopToken']);
        Route::post('/test-send',            [WaOtpServiceController::class, 'testSend']);
        Route::get('/logs',                  [WaOtpServiceController::class, 'logs']);

        // Named API configs (Auth OTP API / Utility / Invoice Share tabs)
        Route::get('/configs',              [WaOtpServiceController::class, 'configs']);
        Route::post('/configs',             [WaOtpServiceController::class, 'storeConfig']);
        Route::patch('/configs/{id}',       [WaOtpServiceController::class, 'updateConfig']);
        Route::delete('/configs/{id}',      [WaOtpServiceController::class, 'destroyConfig']);
        Route::get('/configs/{id}/stats',   [WaOtpServiceController::class, 'configStats']);
        Route::get('/prebuilt-templates',   [WaOtpServiceController::class, 'prebuiltTemplates']);
        Route::get('/sessions',             [WaOtpServiceController::class, 'sessions']);

        Route::post('/utility-send',         [WaOtpServiceController::class, 'utilityMessageSend']);
        Route::post('/invoice-share',        [WaOtpServiceController::class, 'invoiceShare']);
    });

    // WA Cloud OTP Service (management — protected). Independent of the wa-chat
    // "otp-service" block above: own tables, own controller, sends via Meta.
    Route::prefix('wa-cloud/otp-service')->middleware(['company.active'])->group(function () {
        Route::get('/',            [WaCloudApiServiceController::class, 'show']);
        Route::post('/',           [WaCloudApiServiceController::class, 'storeOrUpdate']);
        Route::post('/reset-token', [WaCloudApiServiceController::class, 'resetToken']);
        Route::post('/stop-token',  [WaCloudApiServiceController::class, 'stopToken']);
        Route::post('/test-send',   [WaCloudApiServiceController::class, 'testSend']);
        Route::get('/logs',        [WaCloudApiServiceController::class, 'logs']);

        // Literal paths before "/configs/{id}".
        Route::get('/prebuilt-templates',   [WaCloudApiConfigController::class, 'prebuiltTemplates']);
        Route::get('/configs',              [WaCloudApiConfigController::class, 'index']);
        Route::post('/configs',             [WaCloudApiConfigController::class, 'store']);
        Route::patch('/configs/{id}',       [WaCloudApiConfigController::class, 'update']);
        Route::delete('/configs/{id}',      [WaCloudApiConfigController::class, 'destroy']);
        Route::get('/configs/{id}/stats',   [WaCloudApiConfigController::class, 'stats']);
        Route::post('/configs/{id}/submit', [WaCloudApiConfigController::class, 'submit']);
        Route::post('/configs/{id}/sync',   [WaCloudApiConfigController::class, 'sync']);
        Route::post('/configs/{id}/upload-header-media',   [WaCloudApiConfigController::class, 'uploadHeaderMedia']);
        Route::delete('/configs/{id}/delete-header-media', [WaCloudApiConfigController::class, 'deleteHeaderMedia']);
    });

    // WA Cloud → Inbox Analytics (messages + calls + conversations, filterable)
    Route::prefix('wa-cloud/inbox-analytics')->middleware(['company.active'])->group(function () {
        Route::get('/',       [WaCloudInboxAnalyticsController::class, 'summary']);
        Route::get('/agents', [WaCloudInboxAnalyticsController::class, 'agents']);
    });

    // WA Cloud → Automation Rules (own tables — separate from /wa-agent/automations)
    Route::prefix('wa-cloud/automations')->middleware(['company.active'])->group(function () {
        Route::get('/',             [WaCloudAutomationController::class, 'index']);
        Route::post('/',            [WaCloudAutomationController::class, 'store']);
        Route::get('/logs',         [WaCloudAutomationController::class, 'logs']);
        Route::get('/{id}',         [WaCloudAutomationController::class, 'show']);
        Route::patch('/{id}',       [WaCloudAutomationController::class, 'update']);
        Route::delete('/{id}',      [WaCloudAutomationController::class, 'destroy']);
        Route::post('/{id}/toggle', [WaCloudAutomationController::class, 'toggleActive']);
    });

    // Data Export
    Route::prefix('wa-export')->group(function () {
        Route::get('/',                  [WaExportController::class, 'listJobs']);
        Route::post('/chats',            [WaExportController::class, 'exportChats']);
        Route::post('/contacts',         [WaExportController::class, 'exportContacts']);
        Route::post('/groups',           [WaExportController::class, 'exportGroups']);
        Route::get('/{id}/download',     [WaExportController::class, 'download']);
        Route::delete('/{id}',           [WaExportController::class, 'destroy']);
    });

    // WA Agent — Automation Rules
    Route::prefix('wa-agent/automations')->group(function () {
        Route::get('/',                  [AutomationController::class, 'index']);
        Route::post('/',                 [AutomationController::class, 'store']);
        Route::get('/logs',              [AutomationController::class, 'logs']);
        Route::get('/{id}',              [AutomationController::class, 'show']);
        Route::patch('/{id}',            [AutomationController::class, 'update']);
        Route::delete('/{id}',           [AutomationController::class, 'destroy']);
        Route::post('/{id}/toggle',      [AutomationController::class, 'toggleActive']);
    });

    // WA Agent — Knowledge Base
    Route::prefix('wa-agent/knowledge-base')->group(function () {
        Route::get('/',                  [KnowledgeBaseController::class, 'index']);
        Route::post('/',                 [KnowledgeBaseController::class, 'store']);
        Route::post('/upload',           [KnowledgeBaseController::class, 'upload']);
        Route::get('/{id}',              [KnowledgeBaseController::class, 'show']);
        Route::patch('/{id}',            [KnowledgeBaseController::class, 'update']);
        Route::delete('/{id}',           [KnowledgeBaseController::class, 'destroy']);
        Route::post('/{id}/reprocess',   [KnowledgeBaseController::class, 'reprocess']);
    });

    // WA Agent — Pipelines
    Route::prefix('wa-agent/pipelines')->group(function () {
        Route::get('/',                  [PipelineController::class, 'index']);
        Route::post('/',                 [PipelineController::class, 'store']);
        Route::get('/{id}',              [PipelineController::class, 'show']);
        Route::patch('/{id}',            [PipelineController::class, 'update']);
        Route::delete('/{id}',           [PipelineController::class, 'destroy']);
        Route::post('/{id}/run',         [PipelineController::class, 'run']);
        Route::get('/{id}/runs',         [PipelineController::class, 'runs']);
    });

    // WA Agent — Playbook (per-company agent configuration)
    Route::prefix('wa-agent/playbook')->group(function () {
        Route::get('/',            [AgentPlaybookController::class, 'index']);
        Route::post('/',           [AgentPlaybookController::class, 'store']);
        Route::patch('/{id}',      [AgentPlaybookController::class, 'update']);
        Route::post('/{id}/toggle',[AgentPlaybookController::class, 'toggle']);
        Route::delete('/{id}',     [AgentPlaybookController::class, 'destroy']);
    });

    // WA Agent — AI Agent
    Route::prefix('wa-agent')->group(function () {
        Route::post('/ask',              [AiAgentController::class, 'ask']);
        Route::post('/voice-test',       [AiAgentController::class, 'voiceTest']);
        Route::get('/available-models',  [AiAgentController::class, 'availableModels']);
        Route::get('/ai-settings',       [AiAgentController::class, 'aiSettings']);
        Route::post('/config',           [AiAgentController::class, 'saveConfig']);
        Route::get('/sessions',          [AiAgentController::class, 'sessions']);
        Route::get('/sessions/{id}',     [AiAgentController::class, 'sessionDetail']);
        Route::post('/sessions/{id}/close',    [AiAgentController::class, 'closeSession']);
        Route::post('/sessions/{id}/transfer', [AiAgentController::class, 'transferSession']);
        Route::get('/stats',             [AiAgentController::class, 'stats']);
    });

    // Meta AI / Conversation Intelligence
    Route::prefix('meta-ai')->group(function () {
        Route::get('/config',                     [MetaAiController::class, 'getConfig']);
        Route::post('/config',                    [MetaAiController::class, 'saveConfig']);
        Route::get('/analyses',                   [MetaAiController::class, 'index']);
        Route::get('/analyses/{contactId}',       [MetaAiController::class, 'byContact']);
        Route::get('/lead-scores',                [MetaAiController::class, 'leadScores']);
        Route::get('/conversion-events',          [MetaAiController::class, 'conversionEvents']);
        Route::post('/analyze-now/{contactId}',   [MetaAiController::class, 'analyzeNow']);
        Route::post('/test-analysis',             [MetaAiController::class, 'testAnalysis']);
        Route::get('/stats',                      [MetaAiController::class, 'stats']);
        Route::get('/hot-lead-alerts',            [MetaAiController::class, 'hotLeadAlerts']);
    });

    // Contact intelligence profile
    Route::get('/contacts/{id}/intelligence',     [MetaAiController::class, 'contactProfile']);

    // Settings — Company API Keys
    Route::prefix('settings/api-keys')->group(function () {
        Route::get('/',                      [CompanyApiKeyController::class, 'index']);
        Route::post('/test',                 [CompanyApiKeyController::class, 'testKey']);
        Route::post('/',                     [CompanyApiKeyController::class, 'store']);
        Route::patch('/{id}',                [CompanyApiKeyController::class, 'update']);
        Route::delete('/{id}',               [CompanyApiKeyController::class, 'destroy']);
        Route::post('/{id}/verify',          [CompanyApiKeyController::class, 'verify']);
        Route::post('/{id}/set-active',      [CompanyApiKeyController::class, 'setActive']);
    });
});
