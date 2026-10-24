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
        // Ответы из очереди: пациент у двери терапевта ответил на опросники без регистрации и получил
        // код. Хранятся отдельно от assessments: те неизменяемы и привязаны к пациенту. При регистрации
        // по PIN становятся обычными заполнениями, непривязанные удаляются через 12 часов.
        Schema::create('queue_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->char('code', 4);
            // Хэш токена из cookie браузера пациента: тот же телефон узнаёт свой код и привязывает
            // ответы при регистрации.
            $table->string('browser_token_hash', 64);
            // Опросники пациента: шкала, версия, баллы по вопросам, сумма и степень.
            $table->json('scales');
            // HARS и признаки, которые терапевт заполнил на приёме до регистрации.
            $table->json('doctor_scale')->nullable();
            $table->json('facts')->nullable();
            // Сработавшее правило алертинга (9-й вопрос PHQ-9): красная плашка, пока терапевт не откроет ответы.
            $table->json('alert')->nullable();
            $table->timestamp('submitted_at', 3);
            $table->timestamp('opened_at')->nullable();
            // «Не направлять» и подсказка, при которой решили: выросла подсказка (HARS) — решать снова.
            $table->timestamp('declined_at')->nullable();
            $table->string('declined_suggestion', 16)->nullable();
            $table->text('decline_comment')->nullable();
            $table->foreignId('patient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'submitted_at']);
            $table->index('submitted_at');
        });

        DB::statement("ALTER TABLE queue_answers ADD CONSTRAINT queue_answers_code_check CHECK (code ~ '^[0-9]{4}$')");
        DB::statement('ALTER TABLE queue_answers ADD CONSTRAINT queue_answers_attached_check CHECK (patient_id IS NULL OR attached_at IS NOT NULL)');
        DB::statement("ALTER TABLE queue_answers ADD CONSTRAINT queue_answers_declined_suggestion_check CHECK (declined_suggestion IS NULL OR declined_suggestion IN ('required', 'desirable'))");
        // Код уникален среди непривязанных ответов терапевта: по нему терапевт их и открывает.
        DB::statement('CREATE UNIQUE INDEX queue_answers_pending_code_unique ON queue_answers (doctor_id, code) WHERE attached_at IS NULL');

        // PIN, выданный со страницы кода: после регистрации ответы переходят этому пациенту.
        Schema::table('access_links', function (Blueprint $table) {
            $table->foreignId('queue_answer_id')->nullable()->constrained('queue_answers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('queue_answer_id');
        });

        Schema::dropIfExists('queue_answers');
    }
};
