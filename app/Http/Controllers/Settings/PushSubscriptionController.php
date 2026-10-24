<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Подписка Web Push браузера, в котором пользователь включил уведомления. Браузер присылает её
 * при включении и при каждом открытии страницы «Уведомления»: так подписка остаётся у того,
 * кто сейчас вошёл на этом устройстве.
 */
class PushSubscriptionController extends Controller
{
    /**
     * Сервисы пушей браузеров: Chrome и другие на Chromium, Safari, Firefox, Edge. Сервер
     * отправляет запросы на адрес из подписки, поэтому других адресов не принимаем: иначе
     * через подписку сервер можно заставить обращаться куда угодно.
     */
    private const array PUSH_SERVICES = [
        'fcm.googleapis.com',
        'push.apple.com',
        'push.services.mozilla.com',
        'notify.windows.com',
    ];

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => [
                'required',
                'string',
                'max:'.PushSubscription::ENDPOINT_MAX_LENGTH,
                'url:https',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $this->isPushService((string) $value)) {
                        $fail('Этот браузер не умеет показывать уведомления.');
                    }
                },
            ],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Если в этом браузере раньше был другой пользователь, его подписка удаляется.
        $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
            $validated['contentEncoding'] ?? 'aes128gcm',
        );

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($validated['endpoint']);

        return response()->noContent();
    }

    private function isPushService(string $endpoint): bool
    {
        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        foreach (self::PUSH_SERVICES as $service) {
            if ($host === $service || str_ends_with($host, '.'.$service)) {
                return true;
            }
        }

        return false;
    }
}
