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
        // Настроение после отметки приёма: одно лицо на время приёма (группу) в день. Не опросник
        // и не медицинская запись: пациент меняет выбор до конца дня, поэтому не в assessments.
        Schema::create('mood_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            // Местная дата пациента и время приёма по его часам, как у группы на «Сегодня»: «8:30».
            $table->date('local_date');
            $table->string('slot', 5);
            $table->string('mood', 8);
            // created_at — первый выбор, updated_at — последняя смена.
            $table->timestamps();

            $table->unique(['patient_id', 'local_date', 'slot']);
        });

        DB::statement("ALTER TABLE mood_marks ADD CONSTRAINT mood_marks_mood_check CHECK (mood IN ('sad', 'neutral', 'happy'))");
        DB::statement("ALTER TABLE mood_marks ADD CONSTRAINT mood_marks_slot_check CHECK (slot ~ '^(1?[0-9]|2[0-3]):[0-5][0-9]$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mood_marks');
    }
};
