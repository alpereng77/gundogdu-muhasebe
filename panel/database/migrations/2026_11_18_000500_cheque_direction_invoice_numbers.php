<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Çek/senet yönü (alınan / verilen) ve tahsil / ödeme anındaki banka hareketi
 * - Fatura numarası artık cari bazında tekil (iki tedarikçinin aynı numarası olabilir)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cheques', function (Blueprint $table) {
            $table->string('direction', 8)->default('alinan')->after('type'); // alinan | verilen
            $table->foreignId('bank_transaction_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });

        // Gider faturası ödemesinde kullanılan çekler bizim verdiğimiz çeklerdir.
        DB::table('cheques')->whereIn('id', DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.direction', 'gider')->whereNotNull('payments.cheque_id')->select('payments.cheque_id'))
            ->update(['direction' => 'verilen']);
        DB::table('cheques')->where('status', 'Ödendi')->update(['direction' => 'verilen']);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->index('number');
            $table->unique(['party_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['party_id', 'number']);
            $table->dropIndex(['number']);
            $table->unique('number');
        });
        Schema::table('cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_transaction_id');
            $table->dropColumn('direction');
        });
    }
};
