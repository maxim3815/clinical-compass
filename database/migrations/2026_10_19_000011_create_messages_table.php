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
        // Чат пациента и врача. Диалог — связь врач–пациент: при смене врача начинается новый,
        // а старый остаётся. Сообщения не меняются и не удаляются, поэтому updated_at нет.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_relationship_id')->constrained();
            $table->foreignId('sender_id')->constrained('users');
            // Зашифрован ключом приложения.
            $table->text('body');
            // Когда сообщение прочитал получатель.
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['care_relationship_id', 'id']);
        });

        // Непрочитанные сообщения: счётчики в меню и список диалогов врача.
        DB::statement('CREATE INDEX messages_unread_index ON messages (care_relationship_id, sender_id) WHERE read_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
