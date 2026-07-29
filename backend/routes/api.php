<?php

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
use App\Modules\Flow\Http\Controllers\FlowAnalyticsController;
use App\Modules\Flow\Http\Controllers\FlowController;
use App\Modules\Lead\Http\Controllers\LeadController;
use App\Modules\Lead\Http\Controllers\LeadNoteController;
use App\Modules\Otp\Http\Controllers\OtpController;
use App\Modules\PhoneNumber\Http\Controllers\PhoneNumberController;
use App\Modules\PlanPurchase\Http\Controllers\PlanPurchaseController;
use App\Modules\Report\Http\Controllers\ReportController;
use App\Modules\Settings\Http\Controllers\MessageLogController;
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
    MetaCreativeController,
    MetaAdController,
    MetaInsightController,
    MetaMediaController,
    MetaWebhookController,
};


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

    Route::middleware(['jwt.auth'])->group(function () {

        Route::prefix('auth')->name('auth.')->group(function () {
            Route::get('me',       [AuthController::class, 'me'])->name('me');
            Route::post('refresh', [AuthController::class, 'refresh'])->name('refresh');
            Route::post('logout',  [AuthController::class, 'logout'])->name('logout');
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
                Route::post('companies/{company}/impersonate', [SuperAdminController::class, 'impersonate'])->name('companies.impersonate');
                Route::patch('companies/{company}/status',     [SuperAdminController::class, 'updateStatus'])->name('companies.status');
                Route::get('plans',                            [SuperAdminController::class, 'plans'])->name('plans.index');
                Route::post('plans',                           [SuperAdminController::class, 'createPlan'])->name('plans.store');
                Route::put('plans/{plan}',                     [SuperAdminController::class, 'updatePlan'])->name('plans.update');
                Route::get('users',                            [SuperAdminController::class, 'users'])->name('users.index');
                Route::get('stats',                            [SuperAdminController::class, 'stats'])->name('stats');

                // Staff
                Route::get('staff',           [SuperadminStaffController::class, 'index']); //->name('staff.index');
                Route::post('staff',          [SuperadminStaffController::class, 'store']); //->name('staff.store');
                Route::put('staff/{id}',      [SuperadminStaffController::class, 'update'])->name('staff.update');
                Route::delete('staff/{id}',   [SuperadminStaffController::class, 'destroy'])->name('staff.destroy');
                Route::patch('staff/{id}/toggle', [SuperadminStaffController::class, 'toggle'])->name('staff.toggle');

                // Reports
                Route::get('reports/platform',          [ReportController::class, 'platformReport'])->name('reports.platform');
                Route::get('reports/company/{company}', [ReportController::class, 'companyReport'])->name('reports.company');
                Route::get('reports/purchases',         [ReportController::class, 'purchaseReport'])->name('reports.purchases');

                // Role permissions editor
                Route::get('permissions',         [SuperAdminController::class, 'permissions'])->name('permissions');
                Route::put('permissions/{roleId}', [SuperAdminController::class, 'updatePermissions'])->name('permissions.update');

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
        });

        // ── Message Logs ─────────────────────────────────────────────────────────
        Route::get('message-logs', [MessageLogController::class, 'index'])->name('message-logs.index');
        Route::get('message-logs/{log}', [MessageLogController::class, 'show'])->name('message-logs.show');
        // ── Roles (read-only for company users) ──────────────────────────────────
        Route::prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->name('index');
            Route::get('/{role}', [RoleController::class, 'show'])->name('show');
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

        Route::prefix('leads')->name('leads.')->group(function () {

            Route::get('/',           [LeadController::class, 'index'])->name('index');
            Route::get('/analytics',  [LeadController::class, 'analytics'])->name('analytics');
            Route::post('/import', [LeadController::class, 'import'])->middleware('permission:leads.create');
            Route::get('/export',  [LeadController::class, 'export'])->middleware('permission:leads.view_all');


            Route::get('/{lead}',     [LeadController::class, 'show'])->name('show');

            Route::post('/', [LeadController::class, 'store'])->middleware('permission:leads.create')->name('store');

            Route::put('/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.edit')->name('update');

            Route::post('/{lead}/assign', [LeadController::class, 'assign'])->middleware('permission:leads.assign')->name('assign');

            Route::post('/bulk-assign', [LeadController::class, 'bulkAssign'])->middleware('permission:leads.assign')->name('bulk-assign');

            Route::post('/{lead}/crm-sync', [LeadController::class, 'crmSync'])->middleware('permission:crm.sync')->name('crm-sync');

            Route::delete('/{lead}', [LeadController::class, 'destroy'])->middleware('permission:leads.delete')->name('destroy');

            Route::get('/{lead}/notes',    [LeadNoteController::class, 'index'])->name('notes.index');
            // Notes
            Route::get('/{lead}/events',    [LeadNoteController::class, 'events'])->name('events.index');
            Route::post('/{lead}/notes',   [LeadNoteController::class, 'store'])->name('notes.store');
            Route::delete('/{lead}/notes/{event}', [LeadNoteController::class, 'destroy'])->name('notes.destroy');
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

            Route::middleware('permission:labels.view')->group(function () {
                Route::get('/',        [LabelController::class, 'index'])->name('index');
                Route::get('/{label}', [LabelController::class, 'show'])->name('show');
            });

            Route::middleware('permission:labels.manage')->group(function () {
                Route::post('/',           [LabelController::class, 'store'])->name('store');
                Route::put('/{label}',     [LabelController::class, 'update'])->name('update');
                Route::delete('/{label}',  [LabelController::class, 'destroy'])->name('destroy');
            });
        });

        // ── Contacts ─────────────────────────────────────────────────────────────
        Route::prefix('contacts')->name('contacts.')->group(function () {

            Route::middleware('permission:contacts.view')->group(function () {
                Route::get('/',           [ContactController::class, 'index'])->name('index');
                Route::get('/export',     [ContactController::class, 'export'])->name('export');
                Route::get('/{contact}',  [ContactController::class, 'show'])->name('show');
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

            Route::post('/{contact}/labels', [ContactController::class, 'syncLabels'])
                ->middleware('permission:contacts.edit')
                ->name('sync-labels');


            Route::delete('/{contact}/labels/{label}', [ContactController::class, 'removeLabel'])
                ->middleware('permission:contacts.edit')
                ->name('remove-labels');

            Route::delete('/{contact}', [ContactController::class, 'destroy'])
                ->middleware('permission:contacts.delete')
                ->name('destroy');
        });


        Route::prefix('phone-numbers')->name('phone-numbers.')
            ->middleware(['jwt.auth', 'company.active'])
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


        Route::prefix('flow')->name('flow.')->group(function () {

            // ── Read ─────────────────────────────────────────────────────────────
            Route::middleware('permission:flow.view')->group(function () {
                Route::get('/',            [FlowController::class, 'tree'])->name('tree');
                Route::get('/flat',        [FlowController::class, 'flat'])->name('flat');
                Route::get('/{node}',      [FlowController::class, 'show'])->name('show');
                Route::get('/analytics',   [FlowAnalyticsController::class, 'index'])->name('analytics');
            });

            // ── Manage ───────────────────────────────────────────────────────────
            Route::middleware('permission:flow.manage')->group(function () {
                Route::post('/',              [FlowController::class, 'store'])->name('store');
                Route::put('/{node}',         [FlowController::class, 'update'])->name('update');
                Route::delete('/{node}',      [FlowController::class, 'destroy'])->name('destroy');
                Route::post('/reorder',       [FlowController::class, 'reorder'])->name('reorder');
                Route::patch('/{node}/toggle', [FlowController::class, 'toggle'])->name('toggle');
                Route::post('/duplicate/{node}', [FlowController::class, 'duplicate'])->name('duplicate');
            });
        });

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
    });


    // Public — plan listing (companies can see plans before login)
    Route::get('plans/public', [PlanPurchaseController::class, 'publicPlans'])->name('plans.public');

    // Authenticated company routes
    Route::middleware(['jwt.auth'])->group(function () {
        Route::get('plans',                        [PlanPurchaseController::class, 'index'])->name('plans.index');
        Route::get('plans/current',               [PlanPurchaseController::class, 'currentPlan'])->name('plans.current');
        Route::post('plans/create-order',          [PlanPurchaseController::class, 'createOrder'])->name('plans.create-order');
        Route::post('plans/verify-payment',        [PlanPurchaseController::class, 'verifyPayment'])->name('plans.verify-payment');
        Route::get('plans/history',                [PlanPurchaseController::class, 'history'])->name('plans.history');
        Route::get('addons',                       [PlanPurchaseController::class, 'addons'])->name('addons.index');
        Route::post('addons/{addon}/create-order', [PlanPurchaseController::class, 'addonOrder'])->name('addons.order');
        Route::post('addons/verify-payment',       [PlanPurchaseController::class, 'verifyAddonPayment'])->name('addons.verify');
    });

    // Superadmin plan management
    Route::middleware(['jwt.auth', 'superadmin'])->prefix('superadmin')->group(function () {
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
            Route::get('/',           [TemplateController::class, 'index'])->name('index');
            Route::get('/{id}',       [TemplateController::class, 'show'])->name('show');
            Route::post('/',          [TemplateController::class, 'store'])->name('store')->middleware('plan.limit:templates');
            Route::put('/{id}',       [TemplateController::class, 'update'])->name('update');
            Route::delete('/{id}',    [TemplateController::class, 'destroy'])->name('destroy');
            Route::post('/{id}/sync', [TemplateController::class, 'syncFromMeta'])->name('sync');
        });

    Route::prefix('push')->name('push.')
        ->middleware(['company.active'])
        ->group(function () {
            Route::post('/register-token',   [PushNotificationController::class, 'registerToken'])->middleware('jwt.auth');
            Route::delete('/unregister-token', [PushNotificationController::class, 'unregisterToken'])->middleware('jwt.auth');
            Route::get('/history',           [PushNotificationController::class, 'history'])->middleware('jwt.auth');
            //
        });


    Route::prefix('webhook')->name('webhook.')->group(function () {
        // Meta verification challenge
        Route::get('whatsapp',  [WebhookController::class, 'verify'])->name('verify');
        // Inbound events from Meta
        Route::post('whatsapp', [WebhookController::class, 'handle'])->name('handle');
    });

    // ── Razorpay webhook (public — verified by signature) ────────────────────
    Route::post('razorpay/webhook', [PaymentController::class, 'webhook']);

    // Public webhook
    Route::post('/meta-ads/webhook', [MetaWebhookController::class, 'handle']);

    Route::prefix('/meta-ads')->name('meta-ads.')->middleware(['company.active'])->group(function () {

        // Ad accounts
        Route::get('accounts',                  [MetaAdAccountController::class, 'index']);
        Route::post('accounts',                 [MetaAdAccountController::class, 'store']);
        Route::put('accounts/{id}',             [MetaAdAccountController::class, 'update']);
        Route::delete('accounts/{id}',          [MetaAdAccountController::class, 'destroy']);
        Route::post('accounts/{id}/set-default', [MetaAdAccountController::class, 'setDefault']);
        Route::get('accounts/{id}/verify',      [MetaAdAccountController::class, 'verify']);

        // Audience templates
        Route::get('audience-templates',        [MetaAdSetController::class, 'audienceTemplates']);

        // Campaigns
        Route::get('campaigns',                 [MetaCampaignController::class, 'index']);
        Route::post('campaigns',                [MetaCampaignController::class, 'store']);
        Route::get('campaigns/{id}',            [MetaCampaignController::class, 'show']);
        Route::put('campaigns/{id}',            [MetaCampaignController::class, 'update']);
        Route::delete('campaigns/{id}',         [MetaCampaignController::class, 'destroy']);
        Route::patch('campaigns/{id}/status',   [MetaCampaignController::class, 'updateStatus']);

        // Ad sets
        Route::get('campaigns/{cid}/adsets',    [MetaAdSetController::class, 'index']);
        Route::post('campaigns/{cid}/adsets',   [MetaAdSetController::class, 'store']);
        Route::put('adsets/{id}',               [MetaAdSetController::class, 'update']);
        Route::patch('adsets/{id}/status',      [MetaAdSetController::class, 'updateStatus']);
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
    });
});
