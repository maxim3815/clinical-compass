<?php

namespace App\Http\Controllers\Doctor;

use App\Actions\AcceptReferral;
use App\Actions\DeclineReferral;
use App\Actions\ReferPatient;
use App\Actions\WithdrawReferral;
use App\Enums\ReferralCategory;
use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Support\Assessments\PatientAssessments;
use App\Support\PersonName;
use App\Support\PrescriptionPresenter;
use App\Support\Referrals\ReferralPresenter;
use App\Support\Safety\ClinicalProfilePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Направление к психиатру: терапевт направляет, решает не направлять или отзывает, психиатр
 * смотрит направление и принимает пациента.
 */
class ReferralController extends Controller
{
    public function store(Request $request, User $patient, ReferPatient $referPatient): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'to_doctor_id' => ['required', 'integer', Rule::in(array_column(ReferralPresenter::psychiatrists(), 'id'))],
            'category' => ['required', Rule::enum(ReferralCategory::class)],
            'comment' => ['nullable', 'string', 'max:'.Referral::MAX_COMMENT_LENGTH],
            'suggested' => ['nullable', Rule::enum(ReferralCategory::class)],
        ], [
            'to_doctor_id' => 'Выберите психиатра.',
            'category' => 'Выберите категорию.',
            'comment' => 'Комментарий длиннее '.Referral::MAX_COMMENT_LENGTH.' символов. Сократите его.',
        ]);

        /** @var User $therapist */
        $therapist = $request->user();
        $psychiatrist = User::query()->findOrFail((int) $validated['to_doctor_id']);

        $referPatient->handle(
            $therapist,
            $patient,
            $psychiatrist,
            ReferralCategory::from($validated['category']),
            $validated['comment'] ?? null,
            isset($validated['suggested']) ? ReferralCategory::from($validated['suggested']) : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Направление отправлено: '.PersonName::short($psychiatrist->name).' увидит его в очереди «Требуют внимания».']);

        return back();
    }

    public function decline(Request $request, User $patient, DeclineReferral $declineReferral): RedirectResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:'.Referral::MAX_COMMENT_LENGTH],
            'suggested' => ['nullable', Rule::enum(ReferralCategory::class)],
        ], [
            'comment' => 'Комментарий длиннее '.Referral::MAX_COMMENT_LENGTH.' символов. Сократите его.',
        ]);

        /** @var User $therapist */
        $therapist = $request->user();

        $declineReferral->handle(
            $therapist,
            $patient,
            $validated['comment'] ?? null,
            isset($validated['suggested']) ? ReferralCategory::from($validated['suggested']) : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Решение записано: без направления к психиатру.']);

        return back();
    }

    public function withdraw(Request $request, Referral $referral, WithdrawReferral $withdrawReferral): RedirectResponse
    {
        /** @var User $therapist */
        $therapist = $request->user();
        abort_unless($referral->from_doctor_id === $therapist->id, 404);

        $withdrawn = $withdrawReferral->handle($therapist, $referral);

        Inertia::flash('toast', $withdrawn
            ? ['type' => 'success', 'message' => 'Направление отозвано.']
            : ['type' => 'error', 'message' => 'Психиатр уже принял пациента, отозвать направление нельзя. Список обновлён.']);

        return back();
    }

    public function show(Request $request, Referral $referral): Response|RedirectResponse
    {
        /** @var User $psychiatrist */
        $psychiatrist = $request->user();
        abort_unless($referral->to_doctor_id === $psychiatrist->id && $referral->withdrawn_at === null, 404);

        // Принятого пациента психиатр смотрит уже в его карточке.
        if ($referral->accepted_at !== null) {
            return to_route('doctor.patients.show', $referral->patient_id);
        }

        $patient = $referral->patient()->with('patientProfile')->firstOrFail();
        $today = $patient->localToday();

        return Inertia::render('doctor/Referral', [
            'referral' => ReferralPresenter::present($referral),
            'patient' => [
                'id' => $patient->id,
                'name' => $patient->name,
                'birth_date' => $patient->patientProfile?->birth_date?->format('d.m.Y'),
                'age' => $patient->patientProfile?->age(),
            ],
            'wellbeing' => PatientAssessments::forDoctor($patient),
            'clinicalProfile' => ClinicalProfilePresenter::present($patient),
            'medications' => PrescriptionPresenter::presentList(
                $patient->prescriptions()->current($today)->with(['drug', 'strength', 'intakes', 'prescriber'])->get(),
                $today,
            ),
        ]);
    }

    public function accept(Request $request, Referral $referral, AcceptReferral $acceptReferral): RedirectResponse
    {
        /** @var User $psychiatrist */
        $psychiatrist = $request->user();
        abort_unless($referral->to_doctor_id === $psychiatrist->id, 404);

        if (! $acceptReferral->handle($psychiatrist, $referral)) {
            // Повторное нажатие, в том числе из другой вкладки: пациент уже принят.
            if ($referral->refresh()->accepted_at !== null) {
                return to_route('doctor.patients.show', $referral->patient_id);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => 'Направление отозвано терапевтом. Список обновлён.']);

            return to_route('doctor.patients.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Пациент принят и теперь в вашем списке.']);

        return to_route('doctor.patients.show', $referral->patient_id);
    }
}
