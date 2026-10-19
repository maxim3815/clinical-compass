<?php

namespace App\Http\Controllers\Patient;

use App\Actions\CancelDoseMark;
use App\Actions\SkipDose;
use App\Actions\TakeAllDoses;
use App\Actions\TakeDose;
use App\Enums\DoseEventStatus;
use App\Enums\DoseSkipReason;
use App\Exceptions\DoseNotMarkable;
use App\Http\Controllers\Controller;
use App\Models\DoseEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Пациент отмечает плановые приёмы на экране «Сегодня»: принял, принял в указанное время,
 * не принял с причиной, «Принял всё» на одно время, отмена отметки.
 */
class DoseController extends Controller
{
    public const int MAX_COMMENT_LENGTH = 500;

    /**
     * В группе на одно время больше приёмов не бывает: защита от слишком больших запросов.
     */
    public const int MAX_DOSES_AT_ONCE = 20;

    public function update(Request $request, DoseEvent $doseEvent, TakeDose $takeDose, SkipDose $skipDose): RedirectResponse
    {
        $patient = $this->ownerOf($request, $doseEvent);

        $validated = $request->validate([
            'status' => ['required', Rule::in([DoseEventStatus::Taken->value, DoseEventStatus::Skipped->value])],
            'time' => ['exclude_unless:status,'.DoseEventStatus::Taken->value, 'nullable', 'date_format:H:i'],
            'reason' => ['exclude_unless:status,'.DoseEventStatus::Skipped->value, 'required', Rule::enum(DoseSkipReason::class)],
            'comment' => [
                'exclude_unless:status,'.DoseEventStatus::Skipped->value,
                'nullable',
                'string',
                'max:'.self::MAX_COMMENT_LENGTH,
                'required_if:reason,'.DoseSkipReason::Other->value,
            ],
        ], [
            'status' => 'Отметка указана неверно.',
            'time' => 'Укажите время в формате ЧЧ:ММ.',
            'reason.required' => 'Выберите причину.',
            'reason' => 'Выберите причину из списка.',
            'comment.required_if' => 'Для причины «Другое» напишите комментарий.',
            'comment' => 'Комментарий — не больше '.self::MAX_COMMENT_LENGTH.' символов.',
        ]);

        try {
            if ($validated['status'] === DoseEventStatus::Taken->value) {
                $takeDose->handle($patient, $doseEvent, $validated['time'] ?? null);
            } else {
                $skipDose->handle($patient, $doseEvent, DoseSkipReason::from($validated['reason']), $validated['comment'] ?? null);
            }
        } catch (DoseNotMarkable $e) {
            return $this->rejected($e);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Отметка сохранена.']);

        return to_route('patient.home');
    }

    public function takeAll(Request $request, TakeAllDoses $takeAllDoses): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        $validated = $request->validate([
            'doses' => ['required', 'array', 'max:'.self::MAX_DOSES_AT_ONCE],
            'doses.*' => ['integer', 'distinct'],
        ]);

        $doses = $patient->doseEvents()->whereKey($validated['doses'])->get();
        abort_if($doses->count() !== count($validated['doses']), 404);

        try {
            $marked = $takeAllDoses->handle($patient, $doses);
        } catch (DoseNotMarkable $e) {
            return $this->rejected($e);
        }

        Inertia::flash('toast', $marked > 0
            ? ['type' => 'success', 'message' => 'Приёмы отмечены.']
            : ['type' => 'info', 'message' => 'Эти приёмы уже отмечены.']);

        return to_route('patient.home');
    }

    public function cancel(Request $request, DoseEvent $doseEvent, CancelDoseMark $cancelDoseMark): RedirectResponse
    {
        $patient = $this->ownerOf($request, $doseEvent);

        try {
            $cancelDoseMark->handle($patient, $doseEvent);
        } catch (DoseNotMarkable $e) {
            return $this->rejected($e);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Отметка отменена.']);

        return to_route('patient.home');
    }

    /**
     * Чужой приём не открывается.
     */
    private function ownerOf(Request $request, DoseEvent $doseEvent): User
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_unless($doseEvent->patient_id === $patient->id, 404);

        return $patient;
    }

    /**
     * Страница могла устареть: приём уже не за сегодня или вчера, или пересчёт его убрал.
     */
    private function rejected(DoseNotMarkable $e): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

        return to_route('patient.home');
    }
}
