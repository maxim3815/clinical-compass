<?php

namespace App\Http\Controllers;

use App\Actions\SubmitQueueAnswers;
use App\Enums\DoctorKind;
use App\Enums\UserRole;
use App\Http\Controllers\Concerns\RendersAccessLinkPages;
use App\Http\Requests\Patient\AssessmentRequest;
use App\Models\DoctorProfile;
use App\Models\QueueAnswer;
use App\Models\User;
use App\Support\Assessments\PatientAssessments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * QR-код у двери терапевта: пациент в очереди без регистрации отвечает на опросники и получает код,
 * который называет терапевту на приёме. Телефон помнит свой код: снова открытый QR его покажет.
 */
class QueueController extends Controller
{
    use RendersAccessLinkPages;

    /**
     * Cookie с токеном ответов этого телефона: по нему телефон узнаёт свой код, а регистрация
     * с этого телефона забирает ответы в аккаунт.
     */
    public const string COOKIE = 'queue_answer';

    public function show(Request $request, string $joinCode): Response
    {
        $therapist = $this->therapist($joinCode);

        if ($therapist === null) {
            return $this->invalidLink($request);
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            // Пациент этого терапевта отвечает в приложении: ответы сразу попадут в его карточку.
            if ($user->role === UserRole::Patient && $user->activeCareRelationship?->doctor_id === $therapist->id) {
                return to_route('patient.questionnaires.create');
            }

            return Inertia::render('access-link/SignedIn', ['purpose' => 'queue'])->toResponse($request);
        }

        $answer = $this->ownAnswer($request, $therapist);

        return Inertia::render('queue/Show', [
            'joinCode' => $therapist->doctorProfile?->join_code,
            'doctor' => [
                'name' => $therapist->name,
                'specialty' => $therapist->doctorProfile?->specialty,
            ],
            'answer' => $answer === null ? null : [
                'code' => $answer->code,
                'expires_at' => $answer->expiresLabel(),
                // Ответы подпадают под правило алертинга: экран помощи.
                'help' => $answer->alert !== null,
            ],
            'scales' => $answer === null ? PatientAssessments::questionnaire() : [],
            'lifetimeHours' => QueueAnswer::LIFETIME_HOURS,
        ])->toResponse($request);
    }

    public function store(AssessmentRequest $request, SubmitQueueAnswers $submitQueueAnswers, string $joinCode): RedirectResponse
    {
        $therapist = $this->therapist($joinCode);

        // Повторная отправка (двойное нажатие, «Назад» после кода) показывает тот же код.
        if ($therapist === null || $request->user() !== null || $this->ownAnswer($request, $therapist) !== null) {
            return to_route('queue.show', $joinCode);
        }

        $token = self::token($request) ?? Str::random(40);

        $submitQueueAnswers->handle($therapist, $request->answers(), $token);

        return to_route('queue.show', $joinCode)
            ->withCookie(cookie(self::COOKIE, $token, QueueAnswer::LIFETIME_HOURS * 60, sameSite: 'lax'));
    }

    private function therapist(string $joinCode): ?User
    {
        // Адрес набирают с плаката: заглавные буквы тоже подходят.
        $profile = DoctorProfile::query()
            ->where('join_code', Str::lower($joinCode))
            ->where('kind', DoctorKind::Therapist)
            ->first();

        return $profile?->user;
    }

    private function ownAnswer(Request $request, User $therapist): ?QueueAnswer
    {
        return QueueAnswer::ofBrowser($therapist, self::token($request));
    }

    /**
     * Токен ответов этого телефона из cookie.
     */
    public static function token(Request $request): ?string
    {
        $token = $request->cookie(self::COOKIE);

        return is_string($token) && $token !== '' ? $token : null;
    }
}
