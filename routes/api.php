<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
use App\Http\Controllers\ScrapingController;

Route::post('/scrape', [ScrapingController::class, 'scrapeMultiple']);
Route::post('/duplicate_url', [ScrapingController::class, 'urlChecker']);
Route::post('/tab_cap_url', [ScrapingController::class, 'urlCheckerTabCap']);
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
