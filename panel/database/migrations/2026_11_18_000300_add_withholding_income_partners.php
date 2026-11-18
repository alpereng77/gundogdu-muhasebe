<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Muhasebe genişletmesi:
 *  - Gelir / gider yönü, faturalı / faturasız işlem, kategori
 *  - KDV tevkifat türü (kod) ve gelir/kurumlar vergisi stopajı
 *  - Ortaklar ve inşaat bazlı hisse oranları; ödemeyi yapan / tahsil eden ortak
 *  - KDV-2 ve Muhtasar beyan dönemi takibi
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_types', function (Blueprint $table) {
            $table->string('code', 5)->primary();        // GİB tevkifat kodu: 601, 627 …
            $table->string('name');
            $table->decimal('rate', 3, 2);               // 0,40 = 4/10
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 40)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('partner_project', function (Blueprint $table) {
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->decimal('share', 5, 2)->default(0); // hisse oranı (%)
            $table->primary(['partner_id', 'project_id']);
        });

        Schema::create('tax_periods', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);                 // kdv2 | muhtasar
            $table->char('period', 7);                  // 2026-11
            $table->string('status', 20)->default('Bekliyor');
            $table->date('declared_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['kind', 'period']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('kind', 10)->default('faturali')->after('number');   // faturali | faturasiz
            $table->string('direction', 5)->default('gider')->after('kind');    // gider | gelir
            $table->string('category', 60)->nullable()->after('direction');
            $table->string('counterparty', 200)->nullable()->after('party_id'); // carisiz faturasız işlemde karşı taraf
            $table->string('withholding_code', 5)->nullable()->after('rate');
            $table->decimal('stopaj', 5, 2)->default(0)->after('withholding_code'); // GV/KV stopajı (%)
            $table->index(['project_id', 'direction']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('party_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('cheque_id')->constrained('partners')->restrictOnDelete();
        });

        foreach (\App\Support\Access::DEFAULT_WITHHOLDING_TYPES as $j => [$code, $name, $rate]) {
            DB::table('withholding_types')->insert(['code' => $code, 'name' => $name, 'rate' => $rate, 'active' => true, 'sort' => $j]);
        }

        // Mevcut kayıtlar: 120 Alıcılar carisine kesilen faturalar gelir faturasıdır.
        DB::table('invoices')->whereIn('party_id', DB::table('parties')->where('type', '120 Alıcılar')->select('id'))
            ->update(['direction' => 'gelir']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'direction']);
            $table->dropColumn(['kind', 'direction', 'category', 'counterparty', 'withholding_code', 'stopaj']);
        });
        Schema::dropIfExists('tax_periods');
        Schema::dropIfExists('partner_project');
        Schema::dropIfExists('partners');
        Schema::dropIfExists('withholding_types');
    }
};
