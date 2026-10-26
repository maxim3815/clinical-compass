<?php

namespace App\Http\Controllers\Patient;

use App\Actions\RecordMood;
use App\Enums\Mood;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Настроение на карточке приёма «Сегодня»: три лица после отметки, по желанию.
 */
class MoodController extends Controller
{
    public function store(Request $request, RecordMood $recordMood): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        $validated = $request->validate([
            // Время группы без ведущего нуля, как на «Сегодня»: «8:30».
            'slot' => ['required', 'string', 'regex:/^(1?[0-9]|2[0-3]):[0-5][0-9]$/'],
            'mood' => ['required', Rule::enum(Mood::class)],
        ], [
            'slot' => 'Время приёма указано неверно.',
            'mood' => 'Выберите одно из трёх лиц.',
        ]);

        $recordMood->handle($patient, $validated['slot'], Mood::from($validated['mood']));

        return back();
    }

    /**
     * Повторное касание выбранного лица снимает выбор за сегодня.
     */
    public function destroy(Request $request, RecordMood $recordMood): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        $validated = $request->validate([
            'slot' => ['required', 'string', 'regex:/^(1?[0-9]|2[0-3]):[0-5][0-9]$/'],
        ], ['slot' => 'Время приёма указано неверно.']);

        $recordMood->clear($patient, $validated['slot']);

        return back();
    }
}
