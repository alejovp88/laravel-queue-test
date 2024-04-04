<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\api\EmailSendController;
use App\Http\Controllers\api\CSVImportController;
use App\Http\Controllers\api\CSVtoSQLController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/email/queue/{companyId}/{batchID}', [EmailSendController::class, 'index']);
Route::get('/csv-import/queue/{companyId}', [CSVImportController::class, 'importCSV']);
Route::get('/csv-import/sql/', [CSVtoSQLController::class, 'csvToSQl']);
