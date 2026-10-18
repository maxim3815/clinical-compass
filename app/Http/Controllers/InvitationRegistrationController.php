<?php

namespace App\Http\Controllers;

use App\Actions\RegisterInvitedPatient;
use App\Enums\AccessLinkPurpose;
use App\Exceptions\AccessLinkUnavailable;
use App\Http\Requests\InvitationRegistrationRequest;
use App\Models\AccessLink;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Регистрация пациента по ссылке-приглашению от врача.
 */
class InvitationRegistrationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        // Вошедшему пользователю форму не показываем и ссылку не проверяем.
        if ($request->user() !== null) {
            return Inertia::render('invitation/SignedIn')->toResponse($request);
        }

        $invitation = AccessLink::findActive(AccessLinkPurpose::Invitation, $token);

        if ($invitation === null) {
            return $this->invalid($request);
        }

        return Inertia::render('invitation/Register', [
            'token' => $token,
            'patientName' => $invitation->patient_name,
        ])->toResponse($request);
    }

    public function store(InvitationRegistrationRequest $request, RegisterInvitedPatient $registerInvitedPatient, string $token): RedirectResponse
    {
        /** @var array{name: string, login: string, password: string, birth_date?: string|null} $data */
        $data = $request->validated();

        try {
            $patient = $registerInvitedPatient->handle(
                token: $token,
                name: $data['name'],
                login: $data['login'],
                password: $data['password'],
                birthDate: isset($data['birth_date']) ? CarbonImmutable::parse($data['birth_date']) : null,
            );
        } catch (AccessLinkUnavailable) {
            return to_route('invitation.show', $token);
        }

        Auth::login($patient);
        $request->session()->regenerate();

        return to_route('patient.home');
    }

    /**
     * Один и тот же ответ для несуществующей, истёкшей, использованной и отозванной ссылки.
     */
    private function invalid(Request $request): Response
    {
        return Inertia::render('invitation/Invalid')
            ->toResponse($request)
            ->setStatusCode(Response::HTTP_NOT_FOUND);
    }
}
