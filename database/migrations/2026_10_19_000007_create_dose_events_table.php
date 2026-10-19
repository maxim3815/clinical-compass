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
        // Плановый приём: одна доза регулярного назначения в конкретный момент. Создаются заранее,
        // на сегодня и завтра по местной дате пациента, и остаются привязаны к своей версии назначения.
        Schema::create('dose_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->foreignId('prescription_id')->constrained();
            // Приём назначения — пара (назначение, время суток). Доза запоминается на момент
            // планирования: отмеченный приём хранит то, что было назначено.
            $table->string('time_of_day', 16);
            $table->decimal('tablets', 3, 1);
            // Дата по часовому поясу пациента, к которой относится приём.
            $table->date('local_date');
            $table->timestamp('scheduled_at');
            // Когда окно приёма закрывается: после этого неотмеченный приём пропущен.
            $table->timestamp('window_closes_at');
            $table->string('status', 16);
            $table->timestamps();

            // Один приём назначения в день: повторная и одновременная синхронизация не создаёт дублей.
            $table->unique(['prescription_id', 'time_of_day', 'local_date']);
            $table->index(['patient_id', 'local_date']);
        });

        DB::statement('ALTER TABLE dose_events ADD CONSTRAINT dose_events_window_check CHECK (window_closes_at > scheduled_at)');
        // Таблетка делится только пополам.
        DB::statement('ALTER TABLE dose_events ADD CONSTRAINT dose_events_tablets_check CHECK (tablets > 0 AND tablets * 2 = trunc(tablets * 2))');
        // Scheduler каждую минуту ищет запланированные приёмы с закрытым окном.
        DB::statement("CREATE INDEX dose_events_scheduled_window_index ON dose_events (window_closes_at) WHERE status = 'scheduled'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dose_events');
    }
};
