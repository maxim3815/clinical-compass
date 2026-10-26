<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Drug;
use App\Models\SafetyRule;
use App\Support\Safety\SafetyPresenter;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Страница «Правила»: какие правила проверяют назначение каждого препарата и на какой источник
 * они опираются. Строится из загруженных правил, поэтому не расходится с проверкой.
 */
class SafetyRuleController extends Controller
{
    public function index(): Response
    {
        $drugs = Drug::query()
            ->catalog()
            ->active()
            ->whereHas('safetyRules', fn ($query) => $query->active())
            ->with(['safetyRules' => fn (HasMany $query) => $query->active()->orderBy('position')])
            ->orderBy('name')
            ->get()
            ->map(fn (Drug $drug): array => [
                'code' => $drug->code,
                'name' => $drug->name,
                'rules' => $drug->safetyRules
                    ->map(fn (SafetyRule $rule): array => [
                        ...SafetyPresenter::rule($rule),
                        'condition' => $rule->condition,
                        'codes' => $rule->icd10_codes,
                    ])
                    ->all(),
            ]);

        return Inertia::render('doctor/rules/Index', [
            'drugs' => $drugs,
            'total' => SafetyRule::query()->active()->count(),
        ]);
    }
}
