<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Icd10Code;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Поиск диагноза в справочнике МКБ-10 по коду или названию для поля ввода диагноза.
 */
class Icd10Controller extends Controller
{
    public const int LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());

        if (mb_strlen($search) < 2) {
            return response()->json(['codes' => []]);
        }

        $code = mb_strtoupper(str_replace(',', '.', $search));
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);

        $codes = Icd10Code::query()
            ->where(fn (Builder $query) => $query
                ->whereLike('code', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $code).'%')
                ->orWhereLike('name', '%'.$escaped.'%'))
            // Сначала совпадение по коду, потом рубрики раньше подрубрик.
            ->orderByRaw('CASE WHEN code LIKE ? THEN 0 ELSE 1 END', [$code.'%'])
            ->orderByRaw('length(code)')
            ->orderBy('code')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Icd10Code $icd10): array => ['code' => $icd10->code, 'name' => $icd10->name]);

        return response()->json(['codes' => $codes]);
    }
}
