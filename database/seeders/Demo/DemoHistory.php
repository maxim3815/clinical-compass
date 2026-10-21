<?php

namespace Database\Seeders\Demo;

use App\Actions\AddOwnMedication;
use App\Actions\CancelPrescription;
use App\Actions\CreatePatient;
use App\Actions\MarkMessagesRead;
use App\Actions\PrescribeDrug;
use App\Actions\RecordAsNeededDose;
use App\Actions\SendMessage;
use App\Actions\SetIntakeTimes;
use App\Actions\SkipDose;
use App\Actions\SyncDoseEvents;
use App\Actions\TakeDose;
use App\Enums\PrescriptionKind;
use App\Enums\TimeOfDay;
use App\Models\CareRelationship;
use App\Models\DoseEvent;
use App\Models\Drug;
use App\Models\Message;
use App\Models\Prescription;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\OwnMedicationData;
use App\Support\PrescriptionData;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Проигрывает историю демо-пациента через действия приложения со сдвинутыми часами: врач
 * назначает препарат, пациент выбирает время и отмечает приёмы, они переписываются. Поэтому
 * у истории те же поля и правила, что у настоящей. Чего к текущему моменту ещё не случилось,
 * то не происходит: такие приёмы ждут отметки. Пропуски потом отмечает mark-missed.
 */
final class DemoHistory
{
    /**
     * Шаги истории по порядку времени: момент, номер для равных моментов, действие.
     *
     * @var list<array{CarbonImmutable, int, Closure(): void}>
     */
    private array $steps = [];

    /**
     * Назначения по номеру курса в плане, когда они уже созданы.
     *
     * @var array<int, Prescription>
     */
    private array $prescriptions = [];

    /**
     * Сообщения по номеру в плане, когда они уже отправлены.
     *
     * @var array<int, Message>
     */
    private array $messages = [];

    public function __construct(
        private readonly CreatePatient $createPatient,
        private readonly PrescribeDrug $prescribeDrug,
        private readonly SetIntakeTimes $setIntakeTimes,
        private readonly AddOwnMedication $addOwnMedication,
        private readonly CancelPrescription $cancelPrescription,
        private readonly SyncDoseEvents $syncDoseEvents,
        private readonly TakeDose $takeDose,
        private readonly SkipDose $skipDose,
        private readonly RecordAsNeededDose $recordAsNeededDose,
        private readonly SendMessage $sendMessage,
        private readonly MarkMessagesRead $markMessagesRead,
    ) {}

    public function play(DemoPatient $plan, User $doctor, string $password): User
    {
        $this->steps = [];
        $this->prescriptions = [];
        $this->messages = [];
        // Часы могли быть сдвинуты и до сидера, например в тесте: их и возвращаем.
        $frozen = CarbonImmutable::getTestNow();
        $now = CarbonImmutable::now();
        // Все демо-пациенты в московском поясе: местная дата пациента та же, что у интерфейса.
        $today = DisplayTime::today();

        try {
            // Пациент появляется накануне первого курса: в этот вечер врач назначает препарат.
            $starts = array_map(fn (DemoCourse $course): CarbonImmutable => $today->subDays($course->today - 1), $plan->courses);
            $createdAt = $starts === [] ? $now : $this->moment(min($starts)->subDay(), '18:00');
            $this->travelTo($createdAt);
            $patient = $this->createPatient->handle(
                doctor: $doctor,
                name: $plan->name,
                login: $plan->login,
                password: $password,
                birthDate: CarbonImmutable::parse($plan->birthDate),
                consentedAt: now(),
            );

            foreach ($plan->courses as $index => $course) {
                $this->course($patient, $doctor, $index, $course, $starts[$index]);
            }

            if ($starts !== []) {
                // Синхронизация создаёт приёмы на сегодня и завтра, как это делает scheduler. Через день
                // её достаточно, а сидер работает вдвое быстрее.
                for ($date = min($starts); $date->lte($today); $date = $date->addDays(2)) {
                    $this->step($this->moment($date, '00:05'), fn () => $this->syncDoseEvents->handle($patient));
                }
            }

            foreach ($plan->messages as $index => $message) {
                $this->message($patient, $doctor, $index, $message, $today->subDays($message->daysAgo));
            }

            usort($this->steps, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

            foreach ($this->steps as [$moment, , $action]) {
                if ($moment->gte($now)) {
                    break;
                }

                $this->travelTo($moment);
                $action();
            }
        } finally {
            $this->travelTo($frozen);
        }

        $this->syncDoseEvents->handle($patient);

        return $patient;
    }

    private function course(User $patient, User $doctor, int $index, DemoCourse $course, CarbonImmutable $start): void
    {
        $eve = $start->subDay();
        $drug = Drug::query()->where('code', $course->drug)->sole();
        $strength = $drug->strengths()->where('mg', $course->mg)->sole();

        if ($course->prescribedBy === null) {
            $this->step($this->moment($eve, '18:30'), function () use ($patient, $doctor, $index, $strength, $drug, $course, $start): void {
                $this->prescriptions[$index] = $this->prescribeDrug->handle($doctor, $patient, new PrescriptionData(
                    strength: $strength,
                    kind: PrescriptionKind::Regular,
                    intakes: array_map(fn (): string => '1.0', $course->times),
                    tablets: null,
                    maxDailyDoses: null,
                    instruction: $drug->patient_instruction,
                    startsOn: $start,
                    endsOn: null,
                ));
            });
            $this->step($this->moment($eve, '20:00'), fn () => $this->setIntakeTimes->handle($patient, $this->prescriptions[$index], $course->times));
        } else {
            $this->step($this->moment($eve, '20:30'), function () use ($patient, $index, $drug, $course, $start): void {
                $this->prescriptions[$index] = $this->addOwnMedication->handle($patient, new OwnMedicationData(
                    drug: $drug,
                    drugName: null,
                    dose: "1 таблетка {$course->mg} мг",
                    kind: $course->marks === null ? PrescriptionKind::AsNeeded : PrescriptionKind::Regular,
                    intakes: $course->times,
                    maxDailyDoses: null,
                    startsOn: $start,
                    endsOn: null,
                    prescribedBy: $course->prescribedBy,
                ));
            });
        }

        if ($course->cancelledOn !== null) {
            $this->step($this->moment($start->addDays($course->cancelledOn - 1), '18:00'), fn () => $this->cancelPrescription->handle(
                $doctor,
                $this->prescriptions[$index]->fresh() ?? $this->prescriptions[$index],
                $course->cancelReason,
                $course->cancelComment,
            ));
        }

        for ($day = 1; $day <= $course->lastDay(); $day++) {
            $date = $start->addDays($day - 1);

            foreach ($course->marks === null ? [] : $course->times as $timeOfDay => $time) {
                $mark = ($course->marks)($day, TimeOfDay::from($timeOfDay));

                if ($mark->afterMinutes === null) {
                    continue;
                }

                $this->step($this->moment($date, $time)->addMinutes($mark->afterMinutes), function () use ($patient, $index, $date, $timeOfDay, $mark): void {
                    $dose = DoseEvent::query()
                        ->where('prescription_id', $this->prescriptions[$index]->id)
                        ->where('local_date', $date->toDateString())
                        ->where('time_of_day', $timeOfDay)
                        ->sole();

                    if ($mark->skipReason === null) {
                        $this->takeDose->handle($patient, $dose);
                    } else {
                        $this->skipDose->handle($patient, $dose, $mark->skipReason, $mark->skipComment);
                    }
                });
            }

            foreach ($course->asNeeded[$day] ?? [] as $time) {
                $this->step($this->moment($date, $time), fn () => $this->recordAsNeededDose->handle($patient, $this->prescriptions[$index]));
            }
        }
    }

    private function message(User $patient, User $doctor, int $index, DemoMessage $message, CarbonImmutable $date): void
    {
        [$sender, $reader] = $message->fromPatient ? [$patient, $doctor] : [$doctor, $patient];
        $sentAt = $this->moment($date, $message->time);

        $this->step($sentAt, function () use ($patient, $sender, $index, $message): void {
            $this->messages[$index] = $this->sendMessage->handle($this->careOf($patient), $sender, $message->body);
        });

        if ($message->read) {
            $this->step($sentAt->addMinutes(15), fn () => $this->markMessagesRead->handle($this->careOf($patient), $reader, $this->messages[$index]->id));
        }
    }

    private function careOf(User $patient): CareRelationship
    {
        return CareRelationship::query()->active()->where('patient_id', $patient->id)->sole();
    }

    /**
     * @param  Closure(): mixed  $action
     */
    private function step(CarbonImmutable $moment, Closure $action): void
    {
        $this->steps[] = [$moment, count($this->steps), function () use ($action): void {
            $action();
        }];
    }

    /**
     * Момент по местной дате пациента и местному времени «08:00».
     */
    private function moment(CarbonImmutable $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.$time, DisplayTime::TIMEZONE)->utc();
    }

    /**
     * @param  CarbonInterface|Closure|null  $moment  null — настоящее время
     */
    private function travelTo(CarbonInterface|Closure|null $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
