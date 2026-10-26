<?php

namespace App\Http\Requests\Patient;

use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Enums\TimeOfDay;
use App\Models\Drug;
use App\Models\Prescription;
use App\Models\User;
use App\Support\OwnMedicationData;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Форма препарата, который пациент добавляет сам: новый препарат или изменение своего.
 * Препарат — из справочника или списка препаратов от других врачей (drug_id, а если пациент
 * нашёл его по торговому названию, то и trade_name) или «другой» (drug_id = other и название).
 * При изменении препарат и вид приёма не меняются, а у начавшегося препарата не меняется и дата начала.
 */
class OwnMedicationRequest extends FormRequest
{
    public const string OTHER_DRUG = 'other';

    /**
     * Назначение врача и чужой препарат — 404, до проверки полей.
     */
    public function authorize(): Response
    {
        $prescription = $this->prescription();

        return $prescription === null
            || ($prescription->patient_id === $this->patient()->id && $prescription->source === PrescriptionSource::Patient)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $prescription = $this->prescription();
        $kind = $prescription->kind ?? PrescriptionKind::tryFrom($this->string('kind')->toString());
        $today = $this->patient()->localToday()->toDateString();
        $regularOnly = Rule::excludeIf($kind !== PrescriptionKind::Regular);
        $isOther = $this->input('drug_id') === self::OTHER_DRUG;

        return [
            'drug_id' => $prescription === null
                ? ['bail', 'required', ...($isOther ? [] : ['integer', Rule::exists('drugs', 'id')->where('is_active', true)])]
                : ['exclude'],
            'drug_name' => $prescription === null && $isOther
                ? ['required', 'string', 'max:'.Prescription::MAX_DRUG_NAME_LENGTH]
                : ['exclude'],
            // Торговое название — одно из названий выбранного препарата, как в списке.
            'trade_name' => $prescription === null && ! $isOther
                ? ['nullable', 'string', $this->tradeNameOfDrug(...)]
                : ['exclude'],
            'dose' => ['required', 'string', 'max:'.Prescription::MAX_DOSE_LENGTH],
            'kind' => $prescription === null ? ['required', Rule::enum(PrescriptionKind::class)] : ['exclude'],
            'intakes' => [$regularOnly, 'required', 'list', 'max:'.Prescription::MAX_INTAKES],
            'intakes.*' => [$regularOnly, 'array:time_of_day,time'],
            'intakes.*.time_of_day' => [$regularOnly, 'required', Rule::enum(TimeOfDay::class), 'distinct'],
            'intakes.*.time' => [$regularOnly, 'required', 'date_format:H:i', 'distinct'],
            'max_daily_doses' => [
                Rule::excludeIf($kind !== PrescriptionKind::AsNeeded),
                'nullable', 'integer', 'min:1', 'max:'.Prescription::MAX_DAILY_DOSES,
            ],
            'starts_on' => $this->hasStarted()
                ? ['exclude']
                : ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.($this->hasStarted() ? $today : 'starts_on')],
            'prescribed_by' => ['nullable', 'string', 'max:'.Prescription::MAX_PRESCRIBED_BY_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'drug_id.required' => 'Напишите название и выберите препарат из вариантов под полем.',
            'drug_id' => 'Выберите препарат из вариантов под полем.',
            'drug_name.required' => 'Напишите название препарата.',
            'drug_name' => 'Название длиннее '.Prescription::MAX_DRUG_NAME_LENGTH.' символов. Сократите его.',
            'trade_name' => 'Выберите препарат из вариантов под полем.',
            'dose.required' => 'Напишите дозу, например «1 таблетка 50 мг».',
            'dose' => 'Доза длиннее '.Prescription::MAX_DOSE_LENGTH.' символов. Сократите её.',
            'kind' => 'Выберите, как принимать препарат.',
            'intakes.max' => 'В сутки можно указать не больше '.Prescription::MAX_INTAKES.' приёмов.',
            'intakes' => 'Добавьте хотя бы один приём.',
            'intakes.*.array' => 'Приём описан неверно.',
            'intakes.*.time_of_day.distinct' => 'Для каждого приёма выберите разное время суток.',
            'intakes.*.time_of_day' => 'Выберите время суток.',
            'intakes.*.time.distinct' => 'Время совпадает с другим приёмом.',
            'intakes.*.time.required' => 'Укажите время приёма.',
            'intakes.*.time' => 'Укажите время в формате ЧЧ:ММ.',
            'max_daily_doses' => 'Укажите от 1 до '.Prescription::MAX_DAILY_DOSES.' раз в сутки.',
            'starts_on.after_or_equal' => 'Дата начала не может быть раньше сегодняшнего дня.',
            'starts_on' => 'Укажите дату начала.',
            'ends_on.after_or_equal' => $this->hasStarted()
                ? 'Дата окончания не может быть раньше сегодняшнего дня.'
                : 'Дата окончания не может быть раньше даты начала.',
            'ends_on' => 'Проверьте дату окончания.',
            'prescribed_by' => 'Здесь больше '.Prescription::MAX_PRESCRIBED_BY_LENGTH.' символов. Сократите запись.',
        ];
    }

    public function toData(): OwnMedicationData
    {
        $prescription = $this->prescription();
        $kind = $prescription->kind ?? PrescriptionKind::from($this->string('kind')->toString());

        if ($prescription !== null) {
            $drug = $prescription->drug;
            $drugName = $prescription->drug_name;
            $tradeName = $prescription->trade_name;
        } elseif ($this->input('drug_id') === self::OTHER_DRUG) {
            $drug = null;
            $drugName = $this->string('drug_name')->toString();
            $tradeName = null;
        } else {
            $drug = Drug::query()->findOrFail($this->integer('drug_id'));
            $drugName = null;
            /** @var string|null $tradeName */
            $tradeName = $this->validated('trade_name');
        }

        $intakes = [];

        if ($kind === PrescriptionKind::Regular) {
            /** @var list<array{time_of_day: string, time: string}> $validated */
            $validated = $this->validated('intakes');

            foreach ($validated as $intake) {
                $intakes[$intake['time_of_day']] = $intake['time'];
            }

            uksort($intakes, fn (string $a, string $b): int => TimeOfDay::from($a)->position() <=> TimeOfDay::from($b)->position());
        }

        /** @var string|null $startsOn */
        $startsOn = $this->validated('starts_on');
        /** @var string|null $endsOn */
        $endsOn = $this->validated('ends_on');
        /** @var int|string|null $maxDailyDoses */
        $maxDailyDoses = $kind === PrescriptionKind::AsNeeded ? $this->validated('max_daily_doses') : null;
        /** @var string|null $prescribedBy */
        $prescribedBy = $this->validated('prescribed_by');

        return new OwnMedicationData(
            drug: $drug,
            drugName: $drugName,
            dose: $this->string('dose')->toString(),
            kind: $kind,
            intakes: $intakes,
            maxDailyDoses: $maxDailyDoses === null ? null : (int) $maxDailyDoses,
            startsOn: $startsOn === null && $prescription !== null ? $prescription->starts_on : CarbonImmutable::parse((string) $startsOn),
            endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn),
            prescribedBy: $prescribedBy,
            tradeName: $tradeName,
        );
    }

    public function patient(): User
    {
        /** @var User */
        return $this->user();
    }

    /**
     * Изменяемый препарат или null для нового.
     */
    public function prescription(): ?Prescription
    {
        /** @var Prescription|null */
        return $this->route('prescription');
    }

    /**
     * Торговое название есть у выбранного препарата. Если препарат не прошёл проверку, ошибка уже у drug_id.
     *
     * @param  Closure(string): mixed  $fail
     */
    private function tradeNameOfDrug(string $attribute, mixed $value, Closure $fail): void
    {
        $drugId = filter_var($this->input('drug_id'), FILTER_VALIDATE_INT);
        $drug = $drugId === false ? null : Drug::query()->where('is_active', true)->find($drugId);

        if ($drug !== null && ! in_array($value, $drug->trade_names, true)) {
            $fail($this->messages()['trade_name']);
        }
    }

    /**
     * Препарат уже начали принимать: дата начала у него не меняется, новая версия действует с сегодняшнего дня.
     */
    private function hasStarted(): bool
    {
        $prescription = $this->prescription();

        return $prescription !== null && $prescription->starts_on->lte($this->patient()->localToday());
    }
}
