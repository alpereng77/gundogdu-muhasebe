<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\Access;
use Illuminate\Support\Facades\Route;

Route::get('/giris', [AuthController::class, 'show'])->name('login');
Route::post('/giris', [AuthController::class, 'login']);
Route::post('/cikis', [AuthController::class, 'logout'])->name('logout');

Route::middleware(['auth', EnsureUserIsActive::class])->group(function () {
    // Menü sayfaları
    foreach (Access::allPages() as $key => $page) {
        Route::get($page['path'], [PageController::class, 'show'])->defaults('key', $key);
    }
    // Eski .html bağlantıları yeni adreslere yönlendirilir.
    Route::get('/{file}.html', function (string $file) {
        $path = $file === 'index' ? '/' : '/'.$file;
        return redirect($path.(request()->getQueryString() ? '?'.request()->getQueryString() : ''));
    })->where('file', '[a-z\-]+');

    // Ön yüzün kullandığı JSON uç noktaları (oturum + CSRF korumalı)
    Route::prefix('api')->group(function () {
        Route::post('invoices/parse', [RecordController::class, 'parseInvoice']);
        Route::post('invoices', [RecordController::class, 'storeInvoice']);
        Route::post('invoices/{invoice}', [RecordController::class, 'updateInvoice']);
        Route::delete('invoices/{invoice}', [RecordController::class, 'destroyInvoice']);
        Route::get('invoices/{invoice}/file', [RecordController::class, 'invoiceFile']);
        Route::post('invoices/{invoice}/document', [RecordController::class, 'uploadInvoiceDocument']);
        Route::post('invoices/{invoice}/return', [RecordController::class, 'storeReturn']);
        Route::post('payments', [RecordController::class, 'storePayment']);
        Route::delete('payments/{payment}', [RecordController::class, 'destroyPayment']);
        Route::post('checks', [RecordController::class, 'storeCheque']);
        Route::patch('checks/{cheque}', [RecordController::class, 'updateChequeStatus']);
        Route::put('checks/{cheque}', [RecordController::class, 'updateCheque']);
        Route::delete('checks/{cheque}', [RecordController::class, 'destroyCheque']);
        Route::post('parties', [RecordController::class, 'storeParty']);
        Route::put('parties/{party}', [RecordController::class, 'updateParty']);
        Route::delete('parties/{party}', [RecordController::class, 'destroyParty']);
        Route::post('projects', [RecordController::class, 'storeProject']);
        Route::put('projects/{project}', [RecordController::class, 'renameProject']);
        Route::delete('projects/{project}', [RecordController::class, 'destroyProject']);

        Route::post('settings/company', [SettingsController::class, 'company']);
        Route::post('settings/logo', [SettingsController::class, 'logo']);
        Route::post('settings/theme', [SettingsController::class, 'theme']);
        Route::post('settings/warning', [SettingsController::class, 'warning']);
        Route::post('password', [SettingsController::class, 'password']);
        Route::get('backup', [SettingsController::class, 'backup']);
        Route::post('restore', [SettingsController::class, 'restore']);

        Route::post('partners', [AccountingController::class, 'savePartner']);
        Route::put('partners/{partner}', [AccountingController::class, 'savePartner']);
        Route::delete('partners/{partner}', [AccountingController::class, 'destroyPartner']);
        Route::post('tax-periods', [AccountingController::class, 'saveTaxPeriod']);
        Route::post('withholding-types', [AccountingController::class, 'saveWithholdingType']);
        Route::delete('withholding-types/{code}', [AccountingController::class, 'destroyWithholdingType']);
        Route::post('settings/tax', [AccountingController::class, 'taxSettings']);

        Route::post('bank-accounts', [BankController::class, 'saveAccount']);
        Route::put('bank-accounts/{account}', [BankController::class, 'saveAccount']);
        Route::delete('bank-accounts/{account}', [BankController::class, 'destroyAccount']);
        Route::post('bank-transactions', [BankController::class, 'saveTransaction']);
        Route::put('bank-transactions/{transaction}', [BankController::class, 'saveTransaction']);
        Route::delete('bank-transactions/{transaction}', [BankController::class, 'destroyTransaction']);
        Route::post('bank-transfers', [BankController::class, 'transfer']);

        Route::get('logs', [LogController::class, 'index']);

        Route::post('users', [AdminController::class, 'storeUser']);
        Route::put('users/{user}', [AdminController::class, 'updateUser']);
        Route::delete('users/{user}', [AdminController::class, 'destroyUser']);
        Route::post('roles', [AdminController::class, 'storeRole']);
        Route::put('roles/{role}', [AdminController::class, 'updateRole']);
        Route::delete('roles/{role}', [AdminController::class, 'destroyRole']);
    });
});
