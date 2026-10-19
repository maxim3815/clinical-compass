<?php

namespace App\Http\Controllers\Patient;

use App\Actions\CancelAsNeededDose;
use App\Actions\RecordAsNeededDose;
use App\Enums\PrescriptionKind;
use App\Enums\PrescriptionSource;
use App\Exceptions\DoseNotMarkable;
use App\Exceptions\PrescriptionNotChangeable;
use App\Http\Controllers\Controller;
use App\Models\AsNeededDose;
use App\Models\Prescription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Пациент записывает приёмы «по необходимости» и удаляет ошибочные записи.
 */
class AsNeededDoseController extends Controller
{
    public function store(Request $request, Prescription $prescription, RecordAsNeededDose $recordAsNeededDose): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_unless(
            $prescription->patient_id === $patient->id
                && $prescription->source === PrescriptionSource::Doctor
                && $prescription->kind === PrescriptionKind::AsNeeded,
            404,
        );

        $validated = $request->validate([
            'day' => ['required', Rule::in(['today', 'yesterday'])],
            'time' => ['nullable', 'required_if:day,yesterday', 'date_format:H:i'],
            'confirmed' => ['boolean'],
        ], [
            'day' => 'Выберите день.',
            'time.required_if' => 'Укажите время приёма.',
            'time' => 'Укажите время в формате ЧЧ:ММ.',
        ]);

        try {
            $recordAsNeededDose->handle(
                $patient,
                $prescription,
                $validated['time'] ?? null,
                $validated['day'] === 'yesterday',
                (bool) ($validated['confirmed'] ?? false),
            );
        } catch (PrescriptionNotChangeable) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Назначение уже изменилось или закончилось. Проверьте актуальные назначения.']);

            return to_route('patient.home');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Приём записан.']);

        return to_route('patient.home');
    }

    public function destroy(Request $request, AsNeededDose $asNeededDose, CancelAsNeededDose $cancelAsNeededDose): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        abort_unless($asNeededDose->patient_id === $patient->id, 404);

        try {
            $cancelAsNeededDose->handle($patient, $asNeededDose);
        } catch (DoseNotMarkable $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('patient.home');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Запись о приёме удалена.']);

        return to_route('patient.home');
    }
}
