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
        // Алерт: правило сработало на ответах пациента. Метки времени каждого шага доставки
        // с миллисекундами — по ним считается время доставки врачу.
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            // Лечащий врач в момент срабатывания: ему ушли событие и пуш.
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignId('alert_rule_id')->constrained();
            $table->string('rule_version', 64);
            $table->string('level', 32);
            // Заполнение, на котором сработало правило, и ответы, на которые оно смотрело.
            $table->foreignId('assessment_id')->constrained();
            $table->json('evidence');
            $table->timestamp('submitted_at', 3);
            $table->timestamp('created_at', 3);
            $table->timestamp('broadcast_at', 3)->nullable();
            $table->timestamp('pushed_at', 3)->nullable();
            // Когда алерт появился на экране врача и как: событием в открытом кабинете или при открытии страницы.
            $table->timestamp('shown_at', 3)->nullable();
            $table->string('shown_via', 16)->nullable();
            $table->timestamp('acknowledged_at', 3)->nullable();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users');
            $table->timestamp('closed_at', 3)->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users');
            $table->string('decision', 32)->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('updated_at', 3);

            // Повтор задачи проверки не создаёт второй алерт на то же заполнение.
            $table->unique(['alert_rule_id', 'assessment_id']);
            $table->index(['patient_id', 'closed_at']);
        });

        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_shown_via_check CHECK (shown_via IN ('realtime', 'page'))");
        DB::statement("ALTER TABLE alerts ADD CONSTRAINT alerts_decision_check CHECK (decision IN ('contacted', 'appointment', 'emergency', 'no_threat'))");
        // Закрыть можно только принятый алерт и только с решением.
        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_closed_check CHECK ((closed_at IS NULL) = (decision IS NULL) AND (closed_at IS NULL OR acknowledged_at IS NOT NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
