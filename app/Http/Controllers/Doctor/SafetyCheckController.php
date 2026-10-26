<?php

namespace App\Http\Controllers\Doctor;

use App\Enums\DrugKind;
use App\Enums\PrescriptionSource;
use App\Http\Controllers\Controller;
use App\Models\Drug;
use App\Models\Prescription;
use App\Models\User;
use App\Support\Safety\SafetyCheck;
use App\Support\Safety\SafetyPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Блок проверки в форме назначения: пересчитывается при выборе препарата.
 */
class SafetyCheckController extends Controller
{
    public function show(Request $request, User $patient, SafetyCheck $safetyCheck): JsonResponse
    {
        Gate::authorize('view', $patient);

        $validated = $request->validate([
            'drug_id' => ['required', 'integer', Rule::exists('drugs', 'id')->where('kind', DrugKind::Catalog->value)],
            // Назначение, которое врач меняет: его заменит новая версия.
            'prescription_id' => ['nullable', 'integer'],
        ]);

        $replacing = isset($validated['prescription_id'])
            ? Prescription::query()
                ->where('patient_id', $patient->id)
                ->where('source', PrescriptionSource::Doctor)
                ->whereKey($validated['prescription_id'])
                ->firstOrFail()
            : null;

        $drug = Drug::query()->whereKey($replacing->drug_id ?? $validated['drug_id'])->firstOrFail();

        return response()->json(SafetyPresenter::report($safetyCheck->forCandidate($patient, $drug, $replacing)));
    }
}
