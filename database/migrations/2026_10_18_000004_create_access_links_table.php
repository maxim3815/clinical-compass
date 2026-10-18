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
        // Одноразовые ссылки от врача: приглашение пациента и смена пароля пациента.
        Schema::create('access_links', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 32);
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignId('patient_id')->nullable()->constrained('users');
            // SHA-256 от токена, сам токен не хранится.
            $table->char('token_hash', 64)->unique();
            $table->string('patient_name')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_links');
    }
};
