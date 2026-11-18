<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // İnşaatlar (her biri bağımsız bir muhasebe dosyası)
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Kullanıcı ↔ inşaat erişim tablosu
        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'user_id']);
        });

        // Cari hesaplar (120 Alıcılar / 320 Satıcılar)
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type', 30);
            $table->string('tax')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->date('due');
            $table->decimal('base', 15, 2);
            $table->decimal('vat', 5, 2);
            $table->decimal('rate', 4, 2)->default(0);
            $table->string('description', 1000)->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'date']);
        });

        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('due');
            $table->string('bank')->default('—');
            $table->string('status', 30)->default('Portföyde');
            $table->string('type', 10)->default('Çek');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->string('method', 30);
            $table->foreignId('cheque_id')->nullable()->constrained('cheques')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Firma bilgisi, logo, uyarı günü vb.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->longText('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cheques');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('parties');
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
    }
};
