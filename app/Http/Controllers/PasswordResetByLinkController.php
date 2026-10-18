<?php

namespace App\Http\Controllers;

use App\Actions\ResetPasswordByLink;
use App\Enums\AccessLinkPurpose;
use App\Exceptions\AccessLinkUnavailable;
use App\Http\Controllers\Concerns\RendersAccessLinkPages;
use App\Http\Requests\PasswordResetByLinkRequest;
use App\Models\AccessLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пациент задаёт новый пароль по ссылке, которую выдал врач.
 */
class PasswordResetByLinkController extends Controller
{
    use RendersAccessLinkPages;

    public function show(Request $request, string $token): Response
    {
        if ($request->user() !== null) {
            return $this->signedIn($request);
        }

        $patient = AccessLink::findActive(AccessLinkPurpose::PasswordReset, $token)?->patient;

        if ($patient === null) {
            return $this->invalidLink($request);
        }

        // Логин тоже показываем: кто забыл пароль, мог забыть и его.
        return Inertia::render('password-reset/Reset', [
            'token' => $token,
            'login' => $patient->login,
        ])->toResponse($request);
    }

    public function store(PasswordResetByLinkRequest $request, ResetPasswordByLink $resetPasswordByLink, string $token): RedirectResponse
    {
        /** @var array{password: string} $data */
        $data = $request->validated();

        try {
            $patient = $resetPasswordByLink->handle($token, $data['password']);
        } catch (AccessLinkUnavailable) {
            return to_route('password-reset.show', $token);
        }

        Auth::login($patient);
        $request->session()->regenerate();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Пароль изменён.']);

        return to_route('patient.home');
    }
}
