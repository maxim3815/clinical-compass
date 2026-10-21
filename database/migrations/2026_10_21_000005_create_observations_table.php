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
        // Факты о пациенте. Записи только добавляются: каждое изменение — новая запись,
        // текущей считается последняя по дате факта.
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->foreignId('observation_type_id')->constrained();
            // Значение — одно из трёх полей по виду факта.
            $table->boolean('value_boolean')->nullable();
            $table->decimal('value_number', 8, 2)->nullable();
            $table->string('value_choice', 64)->nullable();
            $table->text('comment')->nullable();
            // Дата, на которую факт верен, по часовому поясу пациента.
            $table->date('observed_on');
            $table->foreignId('recorded_by_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patient_id', 'observation_type_id', 'observed_on']);
        });

        DB::statement('ALTER TABLE observations ADD CONSTRAINT observations_value_check CHECK (num_nonnulls(value_boolean, value_number, value_choice) = 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
