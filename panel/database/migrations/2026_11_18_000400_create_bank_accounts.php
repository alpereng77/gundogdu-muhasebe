<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İnşaat bazlı banka hesapları ve hesap hareketleri.
 * Havale / EFT ile yapılan ödeme ve tahsilatlar bir banka hareketine bağlanabilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->string('bank', 80);                       // Garanti BBVA, Ziraat Bankası …
            $table->string('branch', 80)->nullable();         // şube
            $table->string('name', 120)->nullable();          // hesap adı (ör. Ana hesap, Hakediş hesabı)
            $table->string('iban', 34)->nullable();
            $table->string('account_no', 40)->nullable();
            $table->char('currency', 3)->default('TRY');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->date('opening_date')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('direction', 5);                   // giris | cikis
            $table->decimal('amount', 15, 2);
            $table->string('category', 60)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('document_no', 60)->nullable();    // dekont / referans no
            $table->unsignedBigInteger('transfer_id')->nullable(); // virmanın karşı hareketi
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['bank_account_id', 'date']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('bank_transaction_id')->nullable()->after('partner_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_transaction_id');
        });
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};
