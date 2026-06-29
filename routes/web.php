<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DecisionController;
use App\Http\Controllers\EngineController;
use App\Http\Controllers\IngestionController;
use App\Http\Controllers\InventoryDecisionController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShopifyController;
use App\Http\Controllers\SkuController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class);
    Route::get('skus/{sku}/summary', [SkuController::class, 'summary'])->name('skus.summary');
    Route::resource('skus', SkuController::class)->only(['index', 'show', 'update']);
    Route::post('/engine/run', EngineController::class)->name('engine.run')
        ->middleware('throttle:engine.run');

    Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
    Route::get('/promotions/suggest-uplift', [PromotionController::class, 'suggestUplift'])->name('promotions.suggest-uplift');
    Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
    Route::patch('/promotions/{promotion}', [PromotionController::class, 'update'])->name('promotions.update');
    Route::delete('/promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');

    Route::get('/decisions', [DecisionController::class, 'index'])->name('decisions.index');
    Route::get('/decisions/audit', [DecisionController::class, 'auditLog'])->name('decisions.audit');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::get('/reports', ReportsController::class)->name('reports.index');

    Route::patch('/inventory-decisions/{decision}/status', [InventoryDecisionController::class, 'transition'])
        ->name('inventory-decisions.transition')
        ->middleware('throttle:decision.transition');

    Route::get('/ingestion', [IngestionController::class, 'index'])->name('ingestion.index');
    Route::post('/ingestion/upload', [IngestionController::class, 'upload'])->name('ingestion.upload')
        ->middleware('throttle:ingestion.upload');
    Route::get('/ingestion/template/{entity}', [IngestionController::class, 'template'])->name('ingestion.template');

    Route::post('/ingestion/shopify/connect', [ShopifyController::class, 'store'])->name('ingestion.shopify.connect');
    Route::delete('/ingestion/shopify/disconnect', [ShopifyController::class, 'destroy'])->name('ingestion.shopify.disconnect');
    Route::post('/ingestion/shopify/sync', [ShopifyController::class, 'sync'])->name('ingestion.shopify.sync');
});

require __DIR__.'/auth.php';
