<?php

namespace App\Http\Controllers\Patient;

use App\Actions\SubmitAssessments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\AssessmentRequest;
use App\Models\Assessment;
use App\Models\Scale;
use App\Models\User;
use App\Support\Alerts\AlertEngine;
use App\Support\Assessments\PatientAssessments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Опросники пациента: раздел «Самочувствие», заполнение и экран после отправки.
 */
class AssessmentController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();

        return Inertia::render('patient/Wellbeing', [
            'status' => PatientAssessments::status($patient),
            'scales' => PatientAssessments::forPatient($patient),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        // Без лечащего врача отвечать некому, а без опросников — нечего.
        if (PatientAssessments::status($patient) === null) {
            return to_route('patient.wellbeing');
        }

        return Inertia::render('patient/Questionnaire', [
            'scales' => Scale::query()
                ->active()
                ->orderBy('position')
                ->get()
                ->map(fn (Scale $scale): array => [
                    'code' => $scale->code,
                    'name' => $scale->name,
                    'title' => $scale->patient_title,
                    'instruction' => $scale->instruction,
                    'options' => $scale->options,
                    'items' => array_column($scale->items, 'text'),
                    'source' => [
                        'validation' => $scale->source_validation,
                        'url' => $scale->source_url,
                        'license' => $scale->source_license,
                    ],
                ])
                ->values(),
        ]);
    }

    public function store(AssessmentRequest $request, SubmitAssessments $submitAssessments): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_if(PatientAssessments::status($patient) === null, 404);

        $assessments = $submitAssessments->handle($patient, $request->answers());

        return to_route('patient.questionnaires.sent', $assessments->firstOrFail());
    }

    /**
     * Экран после отправки. Ссылка ведёт на одно из отправленных вместе заполнений.
     *
     * Если ответы подпадают под правило алертинга, вместо «Ответы переданы» — экран помощи.
     * Он зависит только от ответов и правил, а не от того, дошла ли до алерта очередь.
     */
    public function sent(Request $request, Assessment $assessment, AlertEngine $alertEngine): Response
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_unless($assessment->patient_id === $patient->id, 404);

        $doctor = $patient->currentDoctor()->first();
        $submission = Assessment::query()
            ->where('patient_id', $patient->id)
            ->where('submitted_at', $assessment->submitted_at->format('Y-m-d H:i:s.v'))
            ->get();

        return Inertia::render('patient/QuestionnaireSent', [
            'doctor' => $doctor?->name,
            'submittedAt' => $assessment->submitted_at->setTimezone($patient->localTimezone())->isoFormat('D MMMM в HH:mm'),
            'help' => $alertEngine->check($submission) !== [],
        ]);
    }
}
