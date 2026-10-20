<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Манифест приложения: статичный файл в public/.
 *
 * @return array<string, mixed>
 */
function webManifest(): array
{
    return json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
}

test('manifest describes the app installed on a phone', function () {
    expect(webManifest())->toMatchArray([
        'id' => '/',
        'name' => 'Клинический компас',
        'short_name' => 'Компас',
        'lang' => 'ru',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'theme_color' => '#ffffff',
        'background_color' => '#ffffff',
    ]);
});

test('manifest icons exist in the declared sizes', function () {
    /** @var list<array{src: string, sizes: string, type: string, purpose?: string}> $icons */
    $icons = webManifest()['icons'];

    expect(array_map(fn (array $icon) => $icon['sizes'].' '.($icon['purpose'] ?? 'any'), $icons))
        ->toEqualCanonicalizing(['192x192 any', '512x512 any', '512x512 maskable']);

    foreach ($icons as $icon) {
        $image = getimagesize(public_path($icon['src']));

        expect($image)->not->toBeFalse()
            ->and("{$image[0]}x{$image[1]}")->toBe($icon['sizes'])
            ->and($image['mime'])->toBe($icon['type']);
    }
});

test('home screen icon on iphone is 180 pixels', function () {
    $image = getimagesize(public_path('apple-touch-icon.png'));

    expect($image)->not->toBeFalse()
        ->and([$image[0], $image[1]])->toBe([180, 180]);
});

test('pages link the manifest and color the status bar for both themes', function (?string $role) {
    if ($role !== null) {
        $this->actingAs(User::factory()->{$role}()->create());
    }

    $this->get($role === null ? route('login') : route("{$role}.home"))
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="Компас">', false)
        ->assertSee('<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">', false)
        ->assertSee('<meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">', false);
})->with([
    'guest' => null,
    'patient' => 'patient',
]);

test('offline page needs nothing from the network and tells how to mark a dose later', function () {
    $html = (string) file_get_contents(public_path('offline.html'));

    expect($html)
        ->toContain('<h1>Нет подключения к интернету</h1>')
        ->toContain('кнопка «Принял в …» на экране «Сегодня»')
        ->toContain('>Повторить</button>')
        // Стили и скрипт внутри файла, картинка — иконка из предкэша service worker.
        ->not->toMatch('/<script[^>]+src=/')
        ->not->toContain('rel="stylesheet"')
        ->and(preg_match_all('/<img[^>]+src="([^"]+)"/', $html, $images))->toBe(1)
        ->and($images[1])->toBe(['/favicon.svg']);
});

test('patient opens the app section of settings', function () {
    $this->actingAs(User::factory()->patient()->create())
        ->get(route('app.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/App'));
});

test('doctor has no app section in settings', function () {
    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('app.edit'))
        ->assertForbidden();
});

test('guest is sent from the app section to the login page', function () {
    $this->get(route('app.edit'))->assertRedirect(route('login'));
});

test('session lasts 30 days without activity', function () {
    $this->freezeTime();
    $patient = User::factory()->patient()->create();

    $response = $this->post(route('login.store'), [
        'login' => $patient->login,
        'password' => 'password',
    ]);

    $cookie = $response->getCookie(config('session.cookie'), decrypt: false);

    expect(config('session.lifetime'))->toBe(30 * 24 * 60)
        ->and(config('session.expire_on_close'))->toBeFalse()
        ->and($cookie?->getExpiresTime())->toBe(now()->addDays(30)->getTimestamp());
});
