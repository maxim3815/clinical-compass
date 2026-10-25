<?php

namespace App\Actions;

use App\Enums\AccessLinkPurpose;
use App\Events\InvitationChanged;
use App\Exceptions\AccessLinkUnavailable;
use App\Models\AccessLink;
use App\Models\AuditLog;
use App\Models\PatientProfile;
use App\Models\QueueAnswer;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Регистрирует пациента по приглашению и привязывает его к пригласившему врачу.
 * Данные формы должны быть уже проверены.
 *
 * Если пациент до этого отвечал в очереди у двери терапевта, ответы переходят в его аккаунт:
 * выбранные терапевтом (PIN со страницы кода) или с этого же телефона (токен из cookie).
 */
class RegisterInvitedPatient
{
    public function __construct(
        private readonly CreatePatient $createPatient,
        private readonly AttachQueueAnswer $attachQueueAnswer,
    ) {}

    /**
     * @param  string|null  $queueToken  токен ответов из очереди из cookie телефона
     *
     * @throws AccessLinkUnavailable
     */
    public function handle(
        string $token,
        string $name,
        string $login,
        string $password,
        ?DateTimeInterface $birthDate = null,
        ?string $queueToken = null,
    ): User {
        return DB::transaction(function () use ($token, $name, $login, $password, $birthDate, $queueToken): User {
            // Блокировка строки: второй одновременный запрос по той же ссылке дождётся
            // окончания первого и увидит ссылку уже использованной.
            $invitation = AccessLink::query()
                ->where('purpose', AccessLinkPurpose::Invitation)
                ->where('token_hash', AccessLink::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isActive()) {
                throw new AccessLinkUnavailable;
            }

            $patient = $this->createPatient->handle(
                doctor: $invitation->doctor,
                name: $name,
                login: $login,
                password: $password,
                birthDate: $birthDate,
                consentedAt: now(),
            );

            $invitation->update([
                'patient_id' => $patient->id,
                'used_at' => now(),
            ]);

            AuditLog::record($patient, 'patient.consented', $patient, $patient, null, [
                'version' => PatientProfile::CONSENT_VERSION,
            ]);

            $queueAnswer = $this->queueAnswer($invitation, $queueToken);

            if ($queueAnswer !== null) {
                $this->attachQueueAnswer->handle($queueAnswer, $patient);
            }

            InvitationChanged::dispatch($invitation);

            return $patient;
        });
    }

    /**
     * Ответы из очереди для нового пациента. Выбор терапевта важнее телефона: с одного телефона
     * могли ответить двое.
     */
    private function queueAnswer(AccessLink $invitation, ?string $queueToken): ?QueueAnswer
    {
        if ($invitation->queue_answer_id !== null) {
            return $invitation->queueAnswer;
        }

        return QueueAnswer::ofBrowser($invitation->doctor, $queueToken);
    }
}
