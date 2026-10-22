<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\InvitePatient;
use App\Enums\AccessLinkPurpose;
use App\Http\Controllers\Controller;
use App\Models\AccessLink;
use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $doctor */
        $doctor = $request->user();

        $invitations = AccessLink::query()
            ->where('doctor_id', $doctor->id)
            ->where('purpose', AccessLinkPurpose::Invitation)
            // PIN на приёме, который ещё никто не ввёл, — не приглашение: ссылки у него нет.
            ->withLink()
            ->with('patient:id,name')
            ->latest('id')
            ->get()
            ->map(fn (AccessLink $invitation) => [
                'id' => $invitation->id,
                'patient_name' => $invitation->patient->name ?? $invitation->patient_name,
                // Пациент ввёл PIN на приёме и ещё заполняет форму: ФИО появится после регистрации.
                'from_appointment' => $invitation->pin_entered_at !== null,
                'created_at' => DisplayTime::format($invitation->created_at ?? now()),
                'expires_at' => DisplayTime::format($invitation->expires_at),
                'status' => $invitation->status()->value,
                'status_label' => $invitation->status()->label(),
                'can_revoke' => $invitation->isActive(),
            ]);

        return Inertia::render('doctor/Invitations', [
            'invitations' => $invitations,
        ]);
    }

    public function store(Request $request, InvitePatient $invitePatient): RedirectResponse
    {
        $validated = $request->validate([
            'patient_name' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $doctor */
        $doctor = $request->user();

        $issued = $invitePatient->handle($doctor, $validated['patient_name'] ?? null);

        // Ссылку показываем один раз: в БД хранится только хэш токена.
        Inertia::flash('invitationUrl', route('invitation.show', $issued->token));

        return to_route('doctor.invitations.index');
    }

    public function revoke(AccessLink $invitation): RedirectResponse
    {
        Gate::authorize('revoke', $invitation);

        if ($invitation->isActive()) {
            $invitation->update(['revoked_at' => now()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Приглашение отозвано.']);

        return to_route('doctor.invitations.index');
    }
}
