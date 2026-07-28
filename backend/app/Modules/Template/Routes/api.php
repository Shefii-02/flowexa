<?php


// use App\Modules\Template\Http\Controllers\TemplateController;
// use Illuminate\Support\Facades\Route;

// Route::prefix('v1/templates')->name('templates.')
    ->middleware(['jwt.auth', 'company.active'])
    ->group(function () {
        Route::get('/',           [TemplateController::class, 'index'])->name('index');
        Route::get('/{id}',       [TemplateController::class, 'show'])->name('show');
        Route::post('/',          [TemplateController::class, 'store'])->name('store')->middleware('plan.limit:templates');
        Route::put('/{id}',       [TemplateController::class, 'update'])->name('update');
        Route::delete('/{id}',    [TemplateController::class, 'destroy'])->name('destroy');
        Route::post('/{id}/sync', [TemplateController::class, 'syncFromMeta'])->name('sync');
    });
