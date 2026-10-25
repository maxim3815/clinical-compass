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
        Schema::table('patient_profiles', function (Blueprint $table) {
            // Редакция текста согласия, которое пациент принял при регистрации. Пусто — заглушка до настоящего текста.
            $table->string('consent_version', 10)->nullable()->after('consented_at');
            // Пациент прочитал правила «Когда приложение не поможет» и нажал «Понятно». Пока пусто, кабинет
            // открывает экран правил.
            $table->timestamp('rules_acknowledged_at')->nullable()->after('consent_version');
        });

        // Экран правил видят только новые пациенты: уже зарегистрированные считаются ознакомленными.
        DB::table('patient_profiles')->update(['rules_acknowledged_at' => DB::raw('COALESCE(consented_at, created_at, now())')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->dropColumn(['consent_version', 'rules_acknowledged_at']);
        });
    }
};
