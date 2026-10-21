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
        // Находка — сработавшее правило на одном назначении. Появляется и закрывается при
        // синхронизации с данными пациента. Решение врача остаётся в находке и после закрытия.
        Schema::create('safety_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            // Текущая версия назначения: при изменении назначения находка переходит к новой версии.
            $table->foreignId('prescription_id')->constrained();
            $table->foreignId('rule_id')->constrained('safety_rules');
            $table->string('rule_version', 64);
            $table->string('level', 32);
            // На каких данных сработало: строки для врача и назначения, с которыми сочетание.
            $table->jsonb('evidence');
            $table->jsonb('related_prescription_ids')->default('[]');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users');
            // prescribed — обоснование при назначении или изменении, continued — «Продолжить».
            $table->string('decision_kind', 16)->nullable();
            $table->text('decision_text')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'closed_at']);
        });

        DB::statement("ALTER TABLE safety_findings ADD CONSTRAINT safety_findings_level_check CHECK (level IN ('contraindicated', 'caution'))");
        DB::statement(<<<'SQL'
            ALTER TABLE safety_findings ADD CONSTRAINT safety_findings_decision_check CHECK (
                num_nulls(decided_at, decided_by_id, decision_kind, decision_text) IN (0, 4)
                AND (decision_kind IS NULL OR decision_kind IN ('prescribed', 'continued'))
            )
            SQL);
        DB::statement('CREATE UNIQUE INDEX safety_findings_open_unique ON safety_findings (prescription_id, rule_id) WHERE closed_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('safety_findings');
    }
};
