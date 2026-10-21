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
        // Журнал клинических действий: кто, что сделал, с какой записью, данные до и после.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users');
            $table->string('action', 64);
            $table->foreignId('patient_id')->nullable()->constrained('users');
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['patient_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Записи только добавляются: изменить или удалить их не даёт сама база. Функция переживает
        // migrate:fresh (он удаляет только таблицы), поэтому CREATE OR REPLACE.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only: % is not allowed', TG_OP;
            END;
            $$;

            CREATE TRIGGER audit_log_append_only
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_append_only();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_log_append_only()');
    }
};
