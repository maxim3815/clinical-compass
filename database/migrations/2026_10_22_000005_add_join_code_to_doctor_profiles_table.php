<?php

use App\Models\DoctorProfile;
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
        // Постоянный адрес врача /join/<код>: на него ведёт QR на столе, дальше пациент вводит PIN.
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->string('join_code', 32)->nullable()->unique();
        });

        foreach (DB::table('doctor_profiles')->whereNull('join_code')->pluck('user_id') as $userId) {
            DB::table('doctor_profiles')->where('user_id', $userId)->update(['join_code' => DoctorProfile::newJoinCode()]);
        }

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->string('join_code', 32)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropColumn('join_code');
        });
    }
};
