<?php

namespace Database\Seeders\Demo;

use App\Actions\AddDiagnosis;
use App\Actions\AddOwnMedication;
use App\Actions\CancelPrescription;
use App\Actions\CreatePatient;
use App\Actions\MarkMessagesRead;
use App\Actions\PrescribeDrug;
use App\Actions\RecordAsNeededDose;
use App\Actions\RecordObservation;
use App\Actions\ResolveDiagnosis;
use App\Actions\SendMessage;
use App\Actions\SetIntakeTimes;
use App\Actions\SetPatientSex;
use App\Actions\SkipDose;
use App\Actions\SubmitAssessments;
use App\Actions\SyncDoseEvents;
use App\Actions\TakeDose;
use App\Enums\ObservationValueType;
use App\Enums\PrescriptionKind;
use App\Enums\Sex;
use App\Enums\TimeOfDay;
use App\Models\CareRelationship;
use App\Models\Diagnosis;
use App\Models\DoseEvent;
use App\Models\Drug;
use App\Models\Icd10Code;
use App\Models\Message;
use App\Models\ObservationType;
use App\Models\Prescription;
use App\Models\Scale;
use App\Models\User;
use App\Support\DisplayTime;
use App\Support\OwnMedicationData;
use App\Support\PrescriptionData;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Проигрывает историю демо-пациента через действия приложения со сдвинутыми часами: врач
 * заполняет клинический профиль и назначает препарат, пациент выбирает время, отмечает приёмы
 * и заполняет опросники по календарю, они переписываются. Поэтому
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

    /**
     * Диагнозы по коду, когда они уже поставлены.
     *
     * @var array<string, Diagnosis>
     */
    private array $diagnoses = [];

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
        private readonly SetPatientSex $setPatientSex,
        private readonly AddDiagnosis $addDiagnosis,
        private readonly ResolveDiagnosis $resolveDiagnosis,
        private readonly RecordObservation $recordObservation,
        private readonly SubmitAssessments $submitAssessments,
    ) {}

    public function play(DemoPatient $plan, User $doctor, string $password): User
    {
        $this->steps = [];
        $this->prescriptions = [];
        $this->messages = [];
        $this->diagnoses = [];
        // Часы могли быть сдвинуты и до сидера, например в тесте: их и возвращаем.
        $frozen = CarbonImmutable::getTestNow();
        $now = CarbonImmutable::now();
        // Все демо-пациенты в московском поясе: местная дата пациента та же, что у интерфейса.
        $today = DisplayTime::today();

        try {
            // Пациент появляется накануне первого курса: в этот вечер врач назначает препарат. Без
            // курсов — сейчас, а если у него есть опросники — вчера вечером: первый опросник
            // заполнен при регистрации.
            $starts = array_map(fn (DemoCourse $course): CarbonImmutable => $today->subDays($course->today - 1), $plan->courses);
            $createdAt = match (true) {
                $starts !== [] => $this->moment(min($starts)->subDay(), '18:00'),
                $plan->scales !== [] => $this->moment($today->subDay(), '18:00'),
                default => $now,
            };
            $this->travelTo($createdAt);
            $patient = $this->createPatient->handle(
                doctor: $doctor,
                name: $plan->name,
                login: $plan->login,
                password: $password,
                birthDate: CarbonImmutable::parse($plan->birthDate),
                consentedAt: now(),
                // Демо-пациенты правила уже прочитали: экран правил на показе видит только новый пациент.
                rulesAcknowledgedAt: now(),
            );

            $this->profile($patient, $doctor, $plan, $today);

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

            $this->assessments($patient, $plan, $createdAt, $today);

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

    /**
     * Пол, диагнозы и факты врач вносит при заведении пациента, до первого назначения. Смена
     * диагноза позже — шаг истории вечером в свой день.
     */
    private function profile(User $patient, User $doctor, DemoPatient $plan, CarbonImmutable $today): void
    {
        $this->setPatientSex->handle($doctor, $patient, Sex::from($plan->sex));
        $created = $patient->localToday();

        foreach ($plan->diagnoses as $diagnosis) {
            if ($diagnosis->daysAgo === null) {
                $this->diagnose($patient, $doctor, $diagnosis, $created);
            } else {
                $date = $today->subDays($diagnosis->daysAgo);
                $this->step($this->moment($date, '19:00'), fn () => $this->diagnose($patient, $doctor, $diagnosis, $date));
            }

            if ($diagnosis->resolvedDaysAgo !== null) {
                $date = $today->subDays($diagnosis->resolvedDaysAgo);
                $this->step($this->moment($date, '18:55'), fn () => $this->resolveDiagnosis->handle($doctor, $this->diagnoses[$diagnosis->code], $date));
            }
        }

        foreach ($plan->facts as $code => [$value, $comment]) {
            $type = ObservationType::query()->where('code', $code)->sole();

            $this->recordObservation->handle($doctor, $patient, $type, match ($type->value_type) {
                ObservationValueType::Boolean => (bool) $value,
                ObservationValueType::Number => (float) $value,
                ObservationValueType::Choice => (string) $value,
            }, $comment, $created);
        }
    }

    private function diagnose(User $patient, User $doctor, DemoDiagnosis $diagnosis, CarbonImmutable $date): void
    {
        $this->diagnoses[$diagnosis->code] = $this->addDiagnosis->handle(
            $doctor,
            $patient,
            Icd10Code::query()->whereKey($diagnosis->code)->firstOrFail(),
            $diagnosis->main,
            $date,
        );
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

    /**
     * Опросники на каждом наступившем сроке календаря: на дне 0 — сразу после регистрации,
     * на остальных — вечером в день срока. Срок, который наступил сегодня, закрыт заполнением
     * накануне: так картина не зависит от времени запуска сидера.
     */
    private function assessments(User $patient, DemoPatient $plan, CarbonImmutable $createdAt, CarbonImmutable $today): void
    {
        if ($plan->scales === []) {
            return;
        }

        $scales = Scale::query()->active()->forPatients()->orderBy('position')->get();
        $start = CarbonImmutable::parse($createdAt->setTimezone(DisplayTime::TIMEZONE)->toDateString(), 'UTC');

        // У опросников один календарь: они заполняются вместе.
        foreach ($scales->firstOrFail()->calendar()->dueDates($start, $today) as $due) {
            $day = (int) $start->diffInDays($due);
            $answers = [];

            foreach ($scales as $scale) {
                $anchors = $plan->scales[$scale->code] ?? throw new RuntimeException("{$plan->login}: нет сумм опросника {$scale->code}.");
                $answers[$scale->code] = DemoAnswers::split($scale, DemoAnswers::total($anchors, $day));
            }

            $moment = match (true) {
                $day === 0 => $createdAt->addMinutes(20),
                $due->eq($today) => $this->moment($today->subDay(), '19:30'),
                default => $this->moment($due, '19:00'),
            };

            $this->step($moment, fn () => $this->submitAssessments->handle($patient, $answers));
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
