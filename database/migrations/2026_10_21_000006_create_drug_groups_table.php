<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Группы препаратов для правил взаимодействий: серотонинергические, ингибиторы CYP2D6 и т. д.
        // Записи загружает app:drugs:import из database/data/drug-groups.yaml, состав группы
        // записан у препарата в drugs.yaml.
        Schema::create('drug_groups', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('drugs', function (Blueprint $table) {
            // Коды групп из drug_groups.
            $table->jsonb('groups')->default('[]');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drugs', function (Blueprint $table) {
            $table->dropColumn('groups');
        });

        Schema::dropIfExists('drug_groups');
    }
};
