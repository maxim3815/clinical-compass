<?php

namespace App\Http\Controllers;

use App\Actions\RegisterInvitedPatient;
use App\Enums\AccessLinkPurpose;
use App\Exceptions\AccessLinkUnavailable;
use App\Http\Controllers\Concerns\RendersAccessLinkPages;
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
    use RendersAccessLinkPages;

    public function show(Request $request, string $token): Response
    {
        if ($request->user() !== null) {
            return $this->signedIn($request);
        }

        $invitation = AccessLink::findActive(AccessLinkPurpose::Invitation, $token);

        if ($invitation === null) {
            return $this->invalidLink($request);
        }

        return Inertia::render('invitation/Register', [
            'token' => $token,
            'patientName' => $invitation->patient_name,
            // Кто пригласил: пациенту так спокойнее доверять ссылке.
            'doctor' => [
                'name' => $invitation->doctor->name,
                'specialty' => $invitation->doctor->doctorProfile?->specialty,
            ],
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
}
