<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        {{-- Тема только светлая. «only» просит браузер не перекрашивать страницу при тёмной теме в системе --}}
        <meta name="color-scheme" content="only light">

        {{-- Фон до загрузки стилей — как --background в app.css, чтобы страница не мигала белым --}}
        <style>
            html {
                background-color: #fbf9f5;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Приложение на телефоне: манифест, название под иконкой на iPhone и цвет строки состояния по фону --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="apple-mobile-web-app-title" content="Компас">
        <meta name="theme-color" content="#fbf9f5">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
