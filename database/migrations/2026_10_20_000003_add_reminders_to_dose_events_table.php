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
        // Напоминания статус приёма не меняют: это состояние доставки, а не приёма.
        Schema::table('dose_events', function (Blueprint $table) {
            // Когда ушло последнее напоминание: по нему одно и то же напоминание не уходит дважды.
            $table->timestamp('reminded_at')->nullable();
            // «Через 15 минут»: до этого момента обычные повторы не идут, в этот момент приходит напоминание.
            $table->timestamp('snoozed_until')->nullable();
        });

        // Scheduler каждую минуту ищет запланированные приёмы, время которых подошло.
        DB::statement("CREATE INDEX dose_events_scheduled_at_index ON dose_events (scheduled_at) WHERE status = 'scheduled'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX dose_events_scheduled_at_index');

        Schema::table('dose_events', function (Blueprint $table) {
            $table->dropColumn(['reminded_at', 'snoozed_until']);
        });
    }
};
