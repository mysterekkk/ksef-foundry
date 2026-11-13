<?php

use App\Http\Controllers\InvoiceController;
use Illuminate\Support\Facades\Route;

// Token-authenticated JSON API (no cookies, no sessions, hence no CSRF surface). apiPrefix is '' in bootstrap/app.php.
Route::middleware('ksef.token')->prefix('ksef')->group(function (): void {
    Route::get('limits', [InvoiceController::class, 'limits']);
    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/{ksefNumber}', [InvoiceController::class, 'show'])->where('ksefNumber', '[0-9A-Za-z\-]{20,64}');
    Route::post('validate', [InvoiceController::class, 'validateXml']);
    Route::post('invoices', [InvoiceController::class, 'submit']);
    Route::get('status/{session}/{invoice}', [InvoiceController::class, 'status'])->where(['session' => '[0-9A-Za-z\-]+', 'invoice' => '[0-9A-Za-z\-]+']);
    Route::get('upo/{session}/{invoice}', [InvoiceController::class, 'upo'])->where(['session' => '[0-9A-Za-z\-]+', 'invoice' => '[0-9A-Za-z\-]+']);
});
