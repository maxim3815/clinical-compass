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
        // Словарь симптомов из database/data/side-effects.yaml. Симптом, которого больше нет
        // в файле, выключается: на него ссылаются записи пациентов.
        Schema::create('side_effects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('term');
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Группа симптома у препарата по разделу 4 его листка-вкладыша.
        Schema::create('drug_side_effects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drug_id')->constrained()->cascadeOnDelete();
            $table->foreignId('side_effect_id')->constrained()->cascadeOnDelete();
            $table->string('level', 16);
            $table->text('instruction')->nullable();
            $table->unique(['drug_id', 'side_effect_id']);
        });

        DB::statement("ALTER TABLE drug_side_effects ADD CONSTRAINT drug_side_effects_level_check CHECK (level IN ('common', 'uncommon', 'urgent', 'emergency'))");
        DB::statement("ALTER TABLE drug_side_effects ADD CONSTRAINT drug_side_effects_instruction_check CHECK (instruction IS NULL OR level = 'emergency')");

        // Побочный эффект, о котором сообщил пациент или который врач записал со слов пациента.
        // Симптом из словаря или «другое» текстом (side_effect_id пуст). Название и группа
        // сохраняются на момент записи: словарь может измениться.
        Schema::create('adverse_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('side_effect_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);
            $table->string('term')->nullable();
            $table->string('level', 16)->nullable();
            $table->string('severity', 16);
            $table->date('started_on');
            $table->foreignId('reported_by_id')->constrained('users');
            $table->foreignId('alert_id')->nullable()->constrained()->nullOnDelete();
            $table->date('resolved_on')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
        });

        DB::statement("ALTER TABLE adverse_events ADD CONSTRAINT adverse_events_level_check CHECK (level IS NULL OR level IN ('common', 'uncommon', 'urgent', 'emergency'))");
        DB::statement("ALTER TABLE adverse_events ADD CONSTRAINT adverse_events_severity_check CHECK (severity IN ('mild', 'moderate', 'severe'))");
        DB::statement('ALTER TABLE adverse_events ADD CONSTRAINT adverse_events_reviewed_check CHECK ((reviewed_at IS NULL) = (reviewed_by_id IS NULL))');
        DB::statement('ALTER TABLE adverse_events ADD CONSTRAINT adverse_events_resolved_check CHECK (resolved_on IS NULL OR resolved_on >= started_on)');
        // Записи, которые врач ещё не просмотрел: по ним очередь «Требуют внимания» и счётчик.
        DB::statement('CREATE INDEX adverse_events_awaiting_review ON adverse_events (patient_id) WHERE reviewed_at IS NULL');

        // Третий вид правила: срочный побочный эффект. Как у кнопки помощи, условия нет, а открытый
        // алерт у пациента один (индекс alerts_open_help_request_unique по пациенту и правилу):
        // повторный срочный отчёт попадает в него.
        DB::statement('ALTER TABLE alert_rules DROP CONSTRAINT alert_rules_trigger_check');
        DB::statement("ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_trigger_check CHECK (trigger IN ('answers', 'help_request', 'adverse_event'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adverse_events');
        DB::table('alerts')->whereIn('alert_rule_id', DB::table('alert_rules')->where('trigger', 'adverse_event')->select('id'))->delete();
        DB::table('alert_rules')->where('trigger', 'adverse_event')->delete();
        DB::statement('ALTER TABLE alert_rules DROP CONSTRAINT alert_rules_trigger_check');
        DB::statement("ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_trigger_check CHECK (trigger IN ('answers', 'help_request'))");
        Schema::dropIfExists('drug_side_effects');
        Schema::dropIfExists('side_effects');
    }
};
