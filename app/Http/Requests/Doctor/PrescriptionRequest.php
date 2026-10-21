<?php

namespace App\Http\Requests\Doctor;

use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Enums\TimeOfDay;
use App\Models\DrugStrength;
use App\Models\Prescription;
use App\Models\User;
use App\Support\PrescriptionData;
use App\Support\Safety\SafetyAcknowledgement;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Форма назначения: новое назначение или изменение существующего. При изменении препарат
 * и вид приёма не меняются, а у начавшегося назначения не меняется и дата начала.
 */
class PrescriptionRequest extends FormRequest
{
    public const int MAX_INSTRUCTION_LENGTH = 1000;

    public const string ADDED_BY_PATIENT = 'Этот препарат пациент добавил сам, врач его не меняет.';

    private DrugStrength|false|null $strength = false;

    /**
     * Чужой пациент — 404, как у карточки, и до проверки полей. Препарат, который пациент
     * добавил сам, врач не меняет.
     */
    public function authorize(): Response
    {
        $response = Gate::inspect('view', $this->patient());

        if ($response->allowed() && $this->prescription()?->source === PrescriptionSource::Patient) {
            return Response::deny(self::ADDED_BY_PATIENT);
        }

        return $response;
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
        $asNeededOnly = Rule::excludeIf($kind !== PrescriptionKind::AsNeeded);

        $tablets = ['bail', 'required', 'numeric', 'min:0.5', 'max:'.Prescription::MAX_TABLETS, 'multiple_of:0.5'];

        if ($this->strength()?->divisible === false) {
            $tablets[] = 'integer';
        }

        return [
            'drug_id' => $prescription === null
                ? ['bail', 'required', 'integer', Rule::exists('drugs', 'id')->where('is_active', true)]
                : ['exclude'],
            'drug_strength_id' => ['bail', 'required', 'integer', $this->strengthExists()],
            'kind' => $prescription === null ? ['required', Rule::enum(PrescriptionKind::class)] : ['exclude'],
            'intakes' => [$regularOnly, 'required', 'list', 'max:'.Prescription::MAX_INTAKES],
            'intakes.*' => [$regularOnly, 'array:time_of_day,tablets'],
            'intakes.*.time_of_day' => [$regularOnly, 'required', Rule::enum(TimeOfDay::class), 'distinct'],
            'intakes.*.tablets' => [$regularOnly, ...$tablets],
            'tablets' => [$asNeededOnly, ...$tablets],
            'max_daily_doses' => [$asNeededOnly, 'required', 'integer', 'min:1', 'max:'.Prescription::MAX_DAILY_DOSES],
            'instruction' => ['nullable', 'string', 'max:'.self::MAX_INSTRUCTION_LENGTH],
            'starts_on' => $this->hasStarted()
                ? ['exclude']
                : ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.($this->hasStarted() ? $today : 'starts_on')],
            // Проверка безопасности: какие красные находки врач видел в форме и его обоснование.
            // Нужны ли они, решает повторная проверка при сохранении.
            'safety_seen' => ['nullable', 'list', 'max:100'],
            'safety_seen.*' => ['string', 'max:64'],
            'justification' => ['nullable', 'string', 'max:'.SafetyAcknowledgement::MAX_JUSTIFICATION_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tablets = 'Укажите от ½ до '.Prescription::MAX_TABLETS.' таблеток.';

        return [
            'drug_id.required' => 'Выберите препарат.',
            'drug_id' => 'Выберите препарат из справочника.',
            'drug_strength_id.required' => 'Выберите дозировку.',
            'drug_strength_id' => 'Выберите дозировку из справочника.',
            'kind' => 'Выберите, как принимать препарат.',
            'intakes.max' => 'В сутки можно назначить не больше '.Prescription::MAX_INTAKES.' приёмов.',
            'intakes' => 'Добавьте хотя бы один приём.',
            'intakes.*.array' => 'Приём описан неверно.',
            'intakes.*.time_of_day.distinct' => 'Время суток у приёмов не должно повторяться.',
            'intakes.*.time_of_day' => 'Выберите время суток.',
            'intakes.*.tablets.multiple_of' => 'Таблетку можно делить только пополам.',
            'intakes.*.tablets.integer' => 'Таблетку этой дозировки делить нельзя.',
            'intakes.*.tablets' => $tablets,
            'tablets.multiple_of' => 'Таблетку можно делить только пополам.',
            'tablets.integer' => 'Таблетку этой дозировки делить нельзя.',
            'tablets' => $tablets,
            'max_daily_doses' => 'Укажите от 1 до '.Prescription::MAX_DAILY_DOSES.' раз в сутки.',
            'instruction' => 'Инструкция для пациента — не больше '.self::MAX_INSTRUCTION_LENGTH.' символов.',
            'starts_on.after_or_equal' => 'Дата начала не может быть раньше сегодняшнего дня по времени пациента.',
            'starts_on' => 'Укажите дату начала.',
            'ends_on.after_or_equal' => $this->hasStarted()
                ? 'Дата окончания не может быть раньше сегодняшнего дня.'
                : 'Дата окончания не может быть раньше даты начала.',
            'ends_on' => 'Проверьте дату окончания.',
            'safety_seen' => SafetyAcknowledgement::STALE,
            'safety_seen.*' => SafetyAcknowledgement::STALE,
            'justification' => 'Обоснование — не больше '.SafetyAcknowledgement::MAX_JUSTIFICATION_LENGTH.' символов.',
        ];
    }

    public function toData(): PrescriptionData
    {
        $prescription = $this->prescription();
        $kind = $prescription->kind ?? PrescriptionKind::from($this->string('kind')->toString());

        $intakes = [];

        if ($kind === PrescriptionKind::Regular) {
            /** @var list<array{time_of_day: string, tablets: string|int|float}> $validated */
            $validated = $this->validated('intakes');

            foreach ($validated as $intake) {
                $intakes[$intake['time_of_day']] = PrescriptionData::tablets($intake['tablets']);
            }

            uksort($intakes, fn (string $a, string $b): int => TimeOfDay::from($a)->position() <=> TimeOfDay::from($b)->position());
        }

        /** @var string|null $startsOn */
        $startsOn = $this->validated('starts_on');
        /** @var string|null $endsOn */
        $endsOn = $this->validated('ends_on');
        /** @var string|null $instruction */
        $instruction = $this->validated('instruction');

        return new PrescriptionData(
            strength: DrugStrength::query()->findOrFail($this->integer('drug_strength_id')),
            kind: $kind,
            intakes: $intakes,
            tablets: $kind === PrescriptionKind::AsNeeded ? PrescriptionData::tablets($this->string('tablets')->toString()) : null,
            maxDailyDoses: $kind === PrescriptionKind::AsNeeded ? $this->integer('max_daily_doses') : null,
            instruction: $instruction,
            startsOn: $startsOn === null && $prescription !== null ? $prescription->starts_on : CarbonImmutable::parse((string) $startsOn),
            endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn),
        );
    }

    public function toAcknowledgement(): SafetyAcknowledgement
    {
        /** @var list<string> $seen */
        $seen = $this->validated('safety_seen') ?? [];
        /** @var string|null $justification */
        $justification = $this->validated('justification');

        return new SafetyAcknowledgement($seen, $justification);
    }

    public function patient(): User
    {
        /** @var User */
        return $this->route('patient');
    }

    /**
     * Изменяемое назначение или null для нового.
     */
    public function prescription(): ?Prescription
    {
        /** @var Prescription|null */
        return $this->route('prescription');
    }

    /**
     * Назначение уже началось: дата начала у него не меняется, новая версия действует с сегодняшнего дня.
     */
    private function hasStarted(): bool
    {
        $prescription = $this->prescription();

        return $prescription !== null && $prescription->starts_on->lte($this->patient()->localToday());
    }

    /**
     * Выбранная дозировка, чтобы проверить, можно ли делить таблетку.
     */
    private function strength(): ?DrugStrength
    {
        if ($this->strength === false) {
            $id = filter_var($this->input('drug_strength_id'), FILTER_VALIDATE_INT);
            $this->strength = $id === false ? null : DrugStrength::query()->find($id);
        }

        return $this->strength;
    }

    /**
     * Дозировка того же препарата. Выключенную дозировку назначить нельзя,
     * но при изменении назначения можно оставить прежнюю.
     */
    private function strengthExists(): Exists
    {
        $prescription = $this->prescription();
        $drugId = $prescription->drug_id ?? filter_var($this->input('drug_id'), FILTER_VALIDATE_INT);

        return Rule::exists('drug_strengths', 'id')
            ->where('drug_id', $drugId === false ? 0 : $drugId)
            ->where(fn (Builder $query) => $query
                ->where('is_active', true)
                ->when($prescription !== null, fn (Builder $query) => $query->orWhere('id', $prescription?->drug_strength_id)));
    }
}
