<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - İşlem geçmişi: kim, ne zaman, hangi kaydı ekledi / değiştirdi / sildi
 * - İade faturası: asıl faturaya bağlı iade belgesi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 150)->nullable();       // kullanıcı silinse de adı kalır
            $table->string('action', 20);                       // created | updated | deleted
            $table->string('subject_type', 40);                 // invoice, payment, cheque …
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('label', 255)->nullable();           // kaydın okunur adı (fatura no vb.)
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->json('changes')->nullable();                // {alan: [eski, yeni]}
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('return_of_id')->nullable()->after('party_id')->constrained('invoices')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_of_id');
        });
        Schema::dropIfExists('activity_logs');
    }
};
