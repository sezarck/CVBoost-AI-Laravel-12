<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\CvController;
use App\Http\Controllers\Api\CvImportController;
use App\Http\Controllers\Api\CvAnalysisController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PreferenceController;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register',[AuthController::class,'register'])->middleware('throttle:10,1');
        Route::post('/login',[AuthController::class,'login'])->middleware('throttle:10,1');
        Route::post('/forgot-password',[AuthController::class,'forgotPassword'])->middleware('throttle:5,1');
    });
    Route::get('/payments/return/{payment}',[PaymentController::class,'returnFromCheckout'])
        ->middleware('signed')
        ->name('payments.return');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout',[AuthController::class,'logout']);
        Route::get('/me',[MeController::class,'show']);
        Route::patch('/me',[MeController::class,'update']);
        Route::delete('/me',[MeController::class,'destroy']);

        Route::apiResource('cvs',CvController::class);
        Route::post('/cvs/{cv}/duplicate',[CvController::class,'duplicate']);
        Route::get('/cvs/{cv}/export-permission',[PaymentController::class,'exportPermission']);
        Route::post('/cvs/{cv}/export',[PaymentController::class,'consumeExport']);
        Route::post('/cvs/{cv}/modification-session',[CvController::class,'beginModification']);
        Route::delete('/cvs/{cv}/modification-session',[CvController::class,'endModification']);
        Route::post('/cv/import',[CvImportController::class,'store']);
        Route::post('/cv/analyze',[CvAnalysisController::class,'analyze']);
        Route::post('/cv/optimize',[CvAnalysisController::class,'optimize']);

        Route::post('/payments',[PaymentController::class,'store']);
        Route::get('/payments',[PaymentController::class,'index']);
        Route::get('/payments/{payment}',[PaymentController::class,'show']);

        Route::get('/me/preferences',[PreferenceController::class,'show']);
        Route::patch('/me/preferences',[PreferenceController::class,'update']);
    });
});
