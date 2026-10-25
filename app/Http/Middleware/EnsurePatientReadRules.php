<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Новый пациент сначала читает правила «Как работает помощь в приложении»: пока он не нажал «Понятно»,
 * страницы кабинета ведут на экран правил. Экран помощи и чат с врачом открываются всегда: это пути
 * за помощью.
 */
class EnsurePatientReadRules
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // Отправка форм и запросы данных не перехватываются: правила — для страниц.
        if ($user?->mustReadRules() !== true || ! $request->isMethod('GET') || $request->expectsJson() || $request->routeIs('patient.rules.*', 'patient.help.*', 'patient.chat.*')) {
            return $next($request);
        }

        // После «Понятно» пациент попадёт туда, куда шёл, например по пушу.
        $request->session()->put('url.intended', $request->fullUrl());

        return to_route('patient.rules.show');
    }
}
