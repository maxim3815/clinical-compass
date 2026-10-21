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
        // Пол вносит врач. Без него беременность и лактация не проверяются.
        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->string('sex', 16)->nullable();
        });

        DB::statement("ALTER TABLE patient_profiles ADD CONSTRAINT patient_profiles_sex_check CHECK (sex IN ('female', 'male'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table) {
            $table->dropColumn('sex');
        });
    }
};
