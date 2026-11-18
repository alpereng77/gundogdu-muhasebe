<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('ettn', 36)->nullable()->unique()->after('number'); // e-Fatura evrensel tekil no
            $table->json('items')->nullable()->after('description');          // fatura kalemleri
        });
        Schema::table('parties', function (Blueprint $table) {
            $table->index('tax');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['ettn']);
            $table->dropColumn(['ettn', 'items']);
        });
        Schema::table('parties', function (Blueprint $table) {
            $table->dropIndex(['tax']);
        });
    }
};
