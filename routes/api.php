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
use App\Http\Controllers\HelperController;

Route::post('/tab_cap_url', [HelperController::class, 'urlCheckerTabCap']);
Route::post('/duplicate_url', [HelperController::class, 'urlChecker']);
Route::post('/upload_url_txt', [HelperController::class, 'uploadUrl']);
Route::post('/quantiy_adder_checker', [HelperController::class, 'quantityChecker']);
Route::post('/adjust_sumbited_product_obj', [HelperController::class, 'adjustSumitProductObj']);
Route::post('/adjust_sumbited_product_obj_for_coverImage', [HelperController::class, 'adjustCoverImageForUpload']);
Route::post('/scrape', [ScrapingController::class, 'scrapeFromFile']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
