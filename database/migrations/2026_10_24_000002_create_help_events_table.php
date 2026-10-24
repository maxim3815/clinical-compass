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
        // Что пациент делал на экране помощи без «Сообщить врачу»: открыл экран, нажал звонок
        // в 112 или на кризисную линию. Врач видит это тихими строками в карточке, без алерта.
        Schema::create('help_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->string('type', 32);
            $table->timestamp('created_at', 3);

            $table->index(['patient_id', 'created_at']);
        });

        DB::statement("ALTER TABLE help_events ADD CONSTRAINT help_events_type_check CHECK (type IN ('opened', 'call_112', 'call_crisis_line'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('help_events');
    }
};
