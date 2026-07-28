<?php
// ════════════════════════════════════════════════════════════════════════════
// Routes: app/Modules/PlanPurchase/Routes/api.php
// ════════════════════════════════════════════════════════════════════════════

// use App\Modules\PlanPurchase\Http\Controllers\PlanPurchaseController;
// use Illuminate\Support\Facades\Route;

// Route::prefix('v1')->group(function () {

//     // Public — plan listing (companies can see plans before login)
//     Route::get('plans/public', [PlanPurchaseController::class, 'publicPlans'])->name('plans.public');

//     // Authenticated company routes
//     Route::middleware(['jwt.auth'])->group(function () {
//         Route::get('plans',                        [PlanPurchaseController::class, 'index'])->name('plans.index');
//         Route::get('plans/current',               [PlanPurchaseController::class, 'currentPlan'])->name('plans.current');
//         Route::post('plans/create-order',          [PlanPurchaseController::class, 'createOrder'])->name('plans.create-order');
//         Route::post('plans/verify-payment',        [PlanPurchaseController::class, 'verifyPayment'])->name('plans.verify-payment');
//         Route::get('plans/history',                [PlanPurchaseController::class, 'history'])->name('plans.history');
//         Route::get('addons',                       [PlanPurchaseController::class, 'addons'])->name('addons.index');
//         Route::post('addons/{addon}/create-order', [PlanPurchaseController::class, 'addonOrder'])->name('addons.order');
//         Route::post('addons/verify-payment',       [PlanPurchaseController::class, 'verifyAddonPayment'])->name('addons.verify');
//     });

//     // Superadmin plan management
//     Route::middleware(['jwt.auth','superadmin'])->prefix('superadmin')->group(function () {
//         Route::get('plans',             [PlanPurchaseController::class, 'superAdminPlans'])->name('sa.plans.index');
//         Route::post('plans',            [PlanPurchaseController::class, 'superAdminCreatePlan'])->name('sa.plans.store');
//         Route::put('plans/{id}',        [PlanPurchaseController::class, 'superAdminUpdatePlan'])->name('sa.plans.update');
//         Route::delete('plans/{id}',     [PlanPurchaseController::class, 'superAdminDeletePlan'])->name('sa.plans.destroy');
//         Route::post('plans/assign',     [PlanPurchaseController::class, 'assignCustomPlan'])->name('sa.plans.assign');
//         Route::get('addons',            [PlanPurchaseController::class, 'superAdminAddons'])->name('sa.addons.index');
//         Route::post('addons',           [PlanPurchaseController::class, 'superAdminCreateAddon'])->name('sa.addons.store');
//         Route::put('addons/{id}',       [PlanPurchaseController::class, 'superAdminUpdateAddon'])->name('sa.addons.update');
//         Route::get('topup-packages',    [PlanPurchaseController::class, 'topupPackages'])->name('sa.topup.index');
//         Route::post('topup-packages',   [PlanPurchaseController::class, 'createTopupPackage'])->name('sa.topup.store');
//         Route::put('topup-packages/{id}',[PlanPurchaseController::class, 'updateTopupPackage'])->name('sa.topup.update');
//         Route::delete('topup-packages/{id}',[PlanPurchaseController::class,'deleteTopupPackage'])->name('sa.topup.destroy');
//     });
// });
