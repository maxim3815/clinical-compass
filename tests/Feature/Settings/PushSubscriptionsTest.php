<?php

use App\Models\DoseEvent;
use App\Models\User;
use App\Notifications\DoseReminder;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Inertia\Testing\AssertableInertia as Assert;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Подписка браузера, как её присылает страница «Уведомления».
 *
 * @return array<string, mixed>
 */
function browserSubscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-1'): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token'],
        'contentEncoding' => 'aes128gcm',
    ];
}

test('doctor and patient open the notifications page with the public key', function (Closure $user) {
    config(['webpush.vapid.public_key' => 'public-vapid-key']);

    $this->actingAs($user())
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Notifications')
            ->where('vapidPublicKey', 'public-vapid-key'));
})->with([
    'doctor' => fn () => User::factory()->doctor()->create(),
    'patient' => fn () => User::factory()->patient()->create(),
]);

test('a browser subscription is saved for the signed in user', function () {
    $user = User::factory()->patient()->create();

    $this->actingAs($user)->postJson(route('push-subscriptions.store'), browserSubscription())->assertNoContent();

    $subscription = $user->pushSubscriptions()->sole();

    expect($subscription->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/device-1')
        ->and($subscription->public_key)->toBe('p256dh-key')
        ->and($subscription->auth_token)->toBe('auth-token');

    // Повторная отправка той же подписки дубля не создаёт.
    $this->actingAs($user)->postJson(route('push-subscriptions.store'), browserSubscription())->assertNoContent();

    expect(PushSubscription::query()->count())->toBe(1);
});

test('a browser used by another user before moves to the one signed in now', function () {
    $doctor = User::factory()->doctor()->create();
    $patient = User::factory()->patientOf($doctor)->create();

    $this->actingAs($doctor)->postJson(route('push-subscriptions.store'), browserSubscription())->assertNoContent();
    $this->actingAs($patient)->postJson(route('push-subscriptions.store'), browserSubscription())->assertNoContent();

    expect($doctor->pushSubscriptions()->count())->toBe(0)
        ->and($patient->pushSubscriptions()->count())->toBe(1);
});

test('only push services of browsers are accepted', function (string $endpoint) {
    $this->actingAs(User::factory()->patient()->create())
        ->postJson(route('push-subscriptions.store'), browserSubscription($endpoint))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('endpoint');

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'internal service' => 'https://reverb:8080/apps',
    'look-alike host' => 'https://fcm.googleapis.com.example.org/send/1',
    'plain http' => 'http://fcm.googleapis.com/fcm/send/1',
]);

test('push services of Safari, Firefox and Edge are accepted', function (string $endpoint) {
    $this->actingAs(User::factory()->patient()->create())
        ->postJson(route('push-subscriptions.store'), browserSubscription($endpoint))
        ->assertNoContent();
})->with([
    'https://web.push.apple.com/QGuQyavXutnMH9n',
    'https://updates.push.services.mozilla.com/wpush/v2/gAAAAA',
    'https://wns2-db5p.notify.windows.com/w/?token=BQYAAA',
]);

test('turning push off removes only own subscription of this browser', function () {
    $user = User::factory()->patient()->create();
    $other = User::factory()->patient()->create();
    $this->actingAs($user)->postJson(route('push-subscriptions.store'), browserSubscription())->assertNoContent();
    $foreign = subscribeToPush($other);

    $this->actingAs($user)->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $foreign->endpoint])->assertNoContent();
    $this->actingAs($user)->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1'])->assertNoContent();

    expect($user->pushSubscriptions()->count())->toBe(0)
        ->and($other->pushSubscriptions()->count())->toBe(1);
});

test('guests can not manage subscriptions', function () {
    $this->postJson(route('push-subscriptions.store'), browserSubscription())->assertUnauthorized();
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});

test('a subscription the push service no longer knows is removed after delivery', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 05:00', 'UTC'));
    $patient = User::factory()->patient()->create();
    $prescription = timedPrescription($patient, catalogDrug('Эсциталопрам', [10 => true])->strengths()->sole(), ['morning' => '08:00'], [
        'starts_on' => '2026-10-12',
        'effective_at' => now()->subWeek(),
    ]);
    $this->artisan('app:dose-events:sync')->assertSuccessful();
    $gone = subscribeToPush($patient);
    $alive = subscribeToPush($patient);

    // Сервис пушей отвечает 410 Gone на одну подписку и принимает другую.
    $payloads = [];
    $webPush = Mockery::mock(WebPush::class);
    $webPush->shouldReceive('queueNotification')->twice()->andReturnUsing(function (SubscriptionInterface $subscription, string $payload) use (&$payloads): void {
        $payloads[$subscription->getEndpoint()] = json_decode($payload, true);
    });
    $webPush->shouldReceive('flush')->once()->andReturnUsing(function () use ($gone, $alive): Generator {
        yield new MessageSentReport(new Request('POST', $gone->endpoint), new Response(410), false, 'Gone');
        yield new MessageSentReport(new Request('POST', $alive->endpoint), new Response(201));
    });
    $this->app->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => $webPush);

    $patient->notify(new DoseReminder(DoseEvent::query()->where('prescription_id', $prescription->id)->whereDate('local_date', '2026-10-19')->get()));

    expect($payloads[$alive->endpoint]['title'])->toBe('Приём в 8:00')
        ->and($patient->pushSubscriptions()->pluck('id')->all())->toBe([$alive->id]);
});
