<?php

use Illuminate\Foundation\Vite as ViteAssets;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

/**
 * Страница врача тянет за собой десятки чанков. Заголовок Link со всеми ними не помещается
 * в буфер заголовков прокси (у nginx по умолчанию 4 КБ), и прокси отвечает 502. В HTML
 * чанки всё равно перечислены, в заголовке достаточно первых.
 */
test('the link header preloads only the first assets', function () {
    Route::middleware('web')->get('/test/preload', fn () => response('page'));

    Vite::swap(new class extends ViteAssets
    {
        public function preloadedAssets()
        {
            return collect(range(1, 40))
                ->mapWithKeys(fn (int $chunk): array => [
                    "https://compas.test/build/assets/chunk-{$chunk}-DRC__1Mx.js" => ['rel="modulepreload"', 'as="script"'],
                ])
                ->all();
        }
    });

    $response = $this->get('/test/preload')->assertOk();

    expect(substr_count((string) $response->headers->get('Link'), 'rel="modulepreload"'))->toBe(8)
        ->and(strlen((string) $response->headers))->toBeLessThan(4096);
});
