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
        // Правило срабатывает по ответам опросника или по кнопке «Сообщить врачу» на экране
        // помощи. У правила по кнопке нет условия, а источник необязателен: основание — просьба
        // самого пациента.
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->string('trigger', 32)->default('answers')->after('level');
            $table->text('condition')->nullable()->change();
            $table->string('source_document')->nullable()->change();
            $table->string('source_section')->nullable()->change();
            $table->string('source_url', 2048)->nullable()->change();
        });

        DB::statement("ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_trigger_check CHECK (trigger IN ('answers', 'help_request'))");
        DB::statement("ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_condition_check CHECK ((trigger = 'answers') = (condition IS NOT NULL))");
        DB::statement("ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_source_check CHECK (trigger <> 'answers' OR (source_document IS NOT NULL AND source_section IS NOT NULL AND source_url IS NOT NULL))");

        // Алерт по кнопке не связан с опросником. Повторное «Сообщить врачу», пока алерт открыт,
        // не создаёт новый: число повторов и время последнего пишутся в тот же алерт.
        Schema::table('alerts', function (Blueprint $table) {
            $table->foreignId('assessment_id')->nullable()->change();
            $table->unsignedInteger('repeat_count')->default(0)->after('submitted_at');
            $table->timestamp('repeated_at', 3)->nullable()->after('repeat_count');
        });

        DB::statement('ALTER TABLE alerts ADD CONSTRAINT alerts_repeat_check CHECK ((repeat_count = 0) = (repeated_at IS NULL))');
        // Открытый алерт по кнопке у пациента один: повтор нажатия попадает в него.
        DB::statement('CREATE UNIQUE INDEX alerts_open_help_request_unique ON alerts (patient_id, alert_rule_id) WHERE assessment_id IS NULL AND closed_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS alerts_open_help_request_unique');
        DB::statement('ALTER TABLE alerts DROP CONSTRAINT IF EXISTS alerts_repeat_check');
        DB::table('alerts')->whereNull('assessment_id')->delete();

        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn(['repeat_count', 'repeated_at']);
            $table->foreignId('assessment_id')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE alert_rules DROP CONSTRAINT IF EXISTS alert_rules_source_check');
        DB::statement('ALTER TABLE alert_rules DROP CONSTRAINT IF EXISTS alert_rules_condition_check');
        DB::statement('ALTER TABLE alert_rules DROP CONSTRAINT IF EXISTS alert_rules_trigger_check');
        DB::table('alert_rules')->where('trigger', '<>', 'answers')->delete();

        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('trigger');
            $table->text('condition')->nullable(false)->change();
            $table->string('source_document')->nullable(false)->change();
            $table->string('source_section')->nullable(false)->change();
            $table->string('source_url', 2048)->nullable(false)->change();
        });
    }
};
