<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ключ отправки от браузера: ответ на отправку потерялся (сеть, сбой сервера), человек
        // нажимает ещё раз, и повтор с тем же ключом возвращает уже сохранённое сообщение.
        // У старых сообщений ключа нет, NULL уникальности не мешает.
        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('client_key')->nullable();

            $table->unique(['care_relationship_id', 'sender_id', 'client_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['care_relationship_id', 'sender_id', 'client_key']);
            $table->dropColumn('client_key');
        });
    }
};
