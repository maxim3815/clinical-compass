<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Подписка Web Push одного браузера. Схема — как у laravel-notification-channels/webpush:
        // пакет сам читает подписки пользователя и удаляет те, на которые сервис ответил 404 или 410.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subscribable');
            // Адрес браузера в сервисе пушей. Один браузер — одна запись: если в нём войдёт
            // другой пользователь, подписка перейдёт к нему.
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
