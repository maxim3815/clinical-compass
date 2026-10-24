<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\ContinueDespiteFinding;
use App\Http\Controllers\Controller;
use App\Models\SafetyFinding;
use App\Models\User;
use App\Support\Safety\SafetyAcknowledgement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Решение врача по красной находке на идущем назначении: «Продолжить» с обоснованием.
 */
class SafetyFindingController extends Controller
{
    public function continue(Request $request, User $patient, SafetyFinding $safetyFinding, ContinueDespiteFinding $continueDespiteFinding): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'justification' => ['required', 'string', 'max:'.SafetyAcknowledgement::MAX_JUSTIFICATION_LENGTH],
        ], [
            'justification.required' => 'Напишите, почему продолжаете приём вопреки противопоказанию.',
            'justification' => 'Обоснование длиннее '.SafetyAcknowledgement::MAX_JUSTIFICATION_LENGTH.' символов. Сократите его.',
        ]);

        /** @var User $doctor */
        $doctor = $request->user();
        $decided = $continueDespiteFinding->handle($doctor, $safetyFinding, (string) $validated['justification']);

        Inertia::flash('toast', $decided->isEmpty()
            ? ['type' => 'info', 'message' => 'По этому противопоказанию решение уже принято или оно больше не актуально.']
            : ['type' => 'success', 'message' => 'Решение сохранено, приём продолжается.']);

        return to_route('doctor.patients.show', $patient);
    }
}
