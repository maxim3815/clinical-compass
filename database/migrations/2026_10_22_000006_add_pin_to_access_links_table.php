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
        // Приглашение на приёме: сначала у записи только PIN, токен ссылки появляется, когда
        // пациент ввёл верный PIN. До этого по ссылке запись не найти.
        Schema::table('access_links', function (Blueprint $table) {
            $table->char('token_hash', 64)->nullable()->change();
            // HMAC от PIN с ключом приложения: 4 цифры по обычному хэшу перебираются мгновенно.
            $table->char('pin_hash', 64)->nullable();
            $table->timestamp('pin_entered_at')->nullable();
        });

        DB::statement('ALTER TABLE access_links ADD CONSTRAINT access_links_token_or_pin_check CHECK (token_hash IS NOT NULL OR pin_hash IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE access_links DROP CONSTRAINT access_links_token_or_pin_check');
        DB::table('access_links')->whereNull('token_hash')->delete();

        Schema::table('access_links', function (Blueprint $table) {
            $table->dropColumn(['pin_hash', 'pin_entered_at']);
            $table->char('token_hash', 64)->nullable(false)->change();
        });
    }
};
