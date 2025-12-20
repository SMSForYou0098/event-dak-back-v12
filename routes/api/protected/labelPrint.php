<?php

use App\Http\Controllers\LabelPrintController;
use Illuminate\Support\Facades\Route;

Route::prefix('label-prints')->group(function () {
    Route::get('/', [LabelPrintController::class, 'index']);
    Route::post('/bulk', [LabelPrintController::class, 'bulkStore']);
    Route::get('/batch/{batchId}', [LabelPrintController::class, 'getByBatch']);
    Route::patch('/batch/{batchId}/print', [LabelPrintController::class, 'markBatchPrinted']); // New endpoint
    Route::patch('/bulk-status', [LabelPrintController::class, 'bulkUpdateStatus']); // Bulk status update
    Route::delete('/batch/{batchId}', [LabelPrintController::class, 'destroyBatch']);

    Route::get('/{id}', [LabelPrintController::class, 'show']);
    Route::put('/{id}', [LabelPrintController::class, 'update']);
    Route::delete('/{id}', [LabelPrintController::class, 'destroy']);
});
