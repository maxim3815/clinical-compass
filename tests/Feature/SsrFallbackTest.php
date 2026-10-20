<?php

test('a hung ssr server does not hold the page', function () {
    // Сокет принимает соединение, но не отвечает: так выглядит зависший сервер SSR.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = 'http://'.stream_socket_get_name($server, false);

    config([
        'inertia.ssr.enabled' => true,
        'inertia.ssr.url' => $address,
        'inertia.ssr.hot_url' => $address,
        'inertia.ssr.bundle' => __FILE__,
        'inertia.ssr.timeout' => 0.5,
    ]);

    $started = microtime(true);

    $this->get(route('login'))->assertOk();

    expect(microtime(true) - $started)->toBeLessThan(3);

    fclose($server);
});

test('ssr requests time out after five seconds by default', function () {
    expect(config('inertia.ssr.timeout'))->toBe(5.0);
});
