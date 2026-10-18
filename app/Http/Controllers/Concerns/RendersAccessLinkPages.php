<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Общие страницы для одноразовых ссылок от врача: приглашения и смены пароля.
 */
trait RendersAccessLinkPages
{
    /**
     * Вошедшему пользователю форму не показываем и ссылку не проверяем.
     */
    protected function signedIn(Request $request): Response
    {
        return Inertia::render('access-link/SignedIn')->toResponse($request);
    }

    /**
     * Один и тот же ответ для несуществующей, истёкшей, использованной и отозванной ссылки.
     */
    protected function invalidLink(Request $request): Response
    {
        return Inertia::render('access-link/Invalid')
            ->toResponse($request)
            ->setStatusCode(Response::HTTP_NOT_FOUND);
    }
}
