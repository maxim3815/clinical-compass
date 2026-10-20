<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /*
    |--------------------------------------------------------------------------
    | Ключи VAPID
    |--------------------------------------------------------------------------
    |
    | Ключи создаёт `php artisan webpush:vapid` (make init). Если их сменить,
    | все подписки перестанут работать. Контакт для сервисов пушей (subject) —
    | адрес приложения: пакет берёт url('/'), если VAPID_SUBJECT не задан.
    |
    */

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,

    'table_name' => 'push_subscriptions',

    'database_connection' => env('DB_CONNECTION', 'pgsql'),

    // Сервис пушей отвечает за секунды: долгий таймаут только держит воркер очереди.
    'client_options' => [
        'timeout' => 10,
    ],

    'automatic_padding' => true,

];
