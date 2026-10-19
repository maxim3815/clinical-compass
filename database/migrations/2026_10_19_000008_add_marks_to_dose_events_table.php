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
        Schema::table('dose_events', function (Blueprint $table) {
            // Когда пациент принял препарат, по его словам, и когда отметил приём: отметка может
            // прийти и после закрытия окна.
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('marked_at')->nullable();
            // Почему не принял.
            $table->string('skip_reason', 32)->nullable();
            $table->text('skip_comment')->nullable();
        });

        // Время отметки есть только у отмеченного приёма, время приёма — только у принятого,
        // причина — только у непринятого. Для причины «Другое» комментарий обязателен.
        DB::statement(<<<'SQL'
            ALTER TABLE dose_events ADD CONSTRAINT dose_events_mark_check CHECK (
                ((status IN ('taken', 'skipped')) = (marked_at IS NOT NULL))
                AND ((status = 'taken') = (taken_at IS NOT NULL))
                AND ((status = 'skipped') = (skip_reason IS NOT NULL))
                AND (skip_comment IS NULL OR status = 'skipped')
                AND (skip_reason IS DISTINCT FROM 'other' OR skip_comment IS NOT NULL)
            )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE dose_events DROP CONSTRAINT dose_events_mark_check');
        // Отметки удаляются вместе с колонками: приёмы возвращаются к статусу без отметки.
        DB::statement(<<<'SQL'
            UPDATE dose_events
            SET status = CASE WHEN window_closes_at > now() THEN 'scheduled' ELSE 'missed' END
            WHERE status IN ('taken', 'skipped')
            SQL);

        Schema::table('dose_events', function (Blueprint $table) {
            $table->dropColumn(['taken_at', 'marked_at', 'skip_reason', 'skip_comment']);
        });
    }
};
