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
        Schema::table('care_relationships', function (Blueprint $table) {
            // Когда врач последний раз открывал карточку пациента: пропуски до этого момента он видел,
            // и счётчик у пункта меню «Пациенты» их не считает.
            $table->timestamp('adherence_seen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('care_relationships', function (Blueprint $table) {
            $table->dropColumn('adherence_seen_at');
        });
    }
};
