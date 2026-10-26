<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Торговое название, по которому пациент нашёл свой препарат («Кардиомагнил»): пациент видит его,
        // врач — препарат и в скобках это название. Если пациент выбрал препарат по МНН, названия нет.
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('trade_name', 100)->nullable()->after('drug_name');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_trade_name_check CHECK (
                trade_name IS NULL OR (source = 'patient' AND drug_id IS NOT NULL)
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE prescriptions DROP CONSTRAINT prescriptions_trade_name_check');

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('trade_name');
        });
    }
};
