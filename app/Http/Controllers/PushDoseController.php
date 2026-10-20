<?php

namespace App\Http\Controllers;

use App\Actions\SnoozeDoses;
use App\Actions\TakeAllDoses;
use App\Exceptions\DoseNotMarkable;
use App\Models\DoseEvent;
use App\Models\User;
use App\Support\DoseEventPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Кнопки «Принял» и «Через 15 минут» в напоминании о приёме. Запрос присылает service worker
 * по подписанной ссылке из пуша (App\Notifications\DoseReminder): в ней приёмы одного пациента
 * на одно время. Ответ — текст уведомления, которым service worker подтверждает действие.
 */
class PushDoseController extends Controller
{
    public function take(Request $request, TakeAllDoses $takeAllDoses): JsonResponse
    {
        [$patient, $doses] = $this->doses($request);

        try {
            $marked = $takeAllDoses->handle($patient, $doses);
        } catch (DoseNotMarkable $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        return response()->json(['message' => match (true) {
            $marked === 0 => 'Приём уже отмечен',
            $doses->count() > 1 => 'Приёмы отмечены',
            default => 'Приём отмечен',
        }]);
    }

    public function snooze(Request $request, SnoozeDoses $snoozeDoses): JsonResponse
    {
        [$patient, $doses] = $this->doses($request);

        $until = $snoozeDoses->handle($patient, $doses);

        return response()->json(['message' => $until === null
            ? 'Приём уже отмечен'
            : 'Напомним в '.DoseEventPresenter::clock($until, $patient->localTimezone())]);
    }

    /**
     * Список приёмов защищён подписью, но приём мог исчезнуть при пересчёте расписания.
     *
     * @return array{0: User, 1: Collection<int, DoseEvent>}
     */
    private function doses(Request $request): array
    {
        $ids = array_map(intval(...), explode(',', $request->string('doses')->toString()));
        $doses = DoseEvent::query()->whereKey($ids)->get();

        abort_if($doses->isEmpty(), Response::HTTP_NOT_FOUND);

        $patient = User::query()->with('patientProfile')->findOrFail($doses->firstOrFail()->patient_id);

        abort_unless($doses->every(fn (DoseEvent $dose): bool => $dose->patient_id === $patient->id), Response::HTTP_NOT_FOUND);

        return [$patient, $doses];
    }
}
