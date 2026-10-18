<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\IssuePasswordResetLink;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Врач выдаёт пациенту ссылку для смены пароля, если тот забыл пароль.
 */
class PasswordResetLinkController extends Controller
{
    public function store(Request $request, User $patient, IssuePasswordResetLink $issuePasswordResetLink): RedirectResponse
    {
        Gate::authorize('view', $patient);

        /** @var User $doctor */
        $doctor = $request->user();

        $issued = $issuePasswordResetLink->handle($doctor, $patient);

        // Ссылку показываем один раз: в БД хранится только хэш токена.
        Inertia::flash('passwordResetUrl', route('password-reset.show', $issued->token));

        return to_route('doctor.patients.show', $patient);
    }
}
