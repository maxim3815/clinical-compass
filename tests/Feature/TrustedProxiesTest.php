<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/test/request-origin', fn (Request $request) => [
        'secure' => $request->secure(),
        'ip' => $request->ip(),
    ]);
});

test('forwarded headers from a proxy in a private network are trusted', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '10.0.0.5, 93.184.216.34',
        ])
        ->get('/test/request-origin')
        ->assertExactJson(['secure' => true, 'ip' => '93.184.216.34']);
});

test('forwarded headers from a public address are ignored', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '93.184.216.34'])
        ->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '10.0.0.5',
        ])
        ->get('/test/request-origin')
        ->assertExactJson(['secure' => false, 'ip' => '93.184.216.34']);
});

test('redirects behind a tls proxy keep https', function () {
    $response = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get('/')
        ->assertRedirect();

    expect($response->headers->get('Location'))->toStartWith('https://')->toEndWith('/login');
});
