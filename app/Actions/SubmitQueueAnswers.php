<?php

namespace App\Actions;

use App\Models\QueueAnswer;
use App\Models\Scale;
use App\Models\User;
use App\Support\Alerts\AlertEngine;
use App\Support\QueueAnswers\QueueAnswerDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Пациент в очереди у двери терапевта ответил на опросники без регистрации. Ответы получают код
 * из 4 цифр, уникальный среди непривязанных ответов этого терапевта. Если ответы подпадают под
 * правило алертинга (9-й вопрос PHQ-9), у терапевта красная плашка, пока он их не откроет.
 */
class SubmitQueueAnswers
{
    private const int CODE_ATTEMPTS = 50;

    public function __construct(
        private readonly AlertEngine $alertEngine,
        private readonly QueueAnswerDelivery $delivery,
    ) {}

    /**
     * @param  array<string, list<int>>  $answers  код шкалы => балл на каждый вопрос по порядку
     * @param  string  $browserToken  токен из cookie телефона пациента
     */
    public function handle(User $therapist, array $answers, string $browserToken): QueueAnswer
    {
        if (! $therapist->isTherapist()) {
            throw new InvalidArgumentException('Ответы из очереди принимает терапевт.');
        }

        $scales = Scale::query()->active()->forPatients()->orderBy('position')->get();
        $answers = array_map(array_values(...), $answers);

        if ($scales->isEmpty()) {
            throw new InvalidArgumentException('Опросников для заполнения нет.');
        }

        // Проверка до записи: неполные ответы не сохраняются.
        $entries = $scales->map(function (Scale $scale) use ($answers): array {
            $total = $scale->total($answers[$scale->code] ?? []);

            return [
                'scale_id' => $scale->id,
                'scale' => $scale->code,
                'version' => $scale->version,
                'scores' => $answers[$scale->code],
                'total' => $total,
                'band' => $scale->band($total),
            ];
        })->values()->all();

        $answer = DB::transaction(function () use ($therapist, $entries, $browserToken): QueueAnswer {
            // Два ответа одновременно не получат один код: второй ждёт первый.
            User::query()->whereKey($therapist->id)->lockForUpdate()->first();

            $answer = new QueueAnswer([
                'doctor_id' => $therapist->id,
                'code' => $this->freeCode($therapist),
                'browser_token_hash' => QueueAnswer::hashToken($browserToken),
                'scales' => $entries,
                'submitted_at' => CarbonImmutable::now(),
            ]);

            $match = $this->alertEngine->check($answer->assessments(withDoctorScale: false))[0] ?? null;

            if ($match !== null) {
                $answer->alert = [
                    'rule_id' => $match->rule->id,
                    'rule_version' => $match->rule->version,
                    'evidence' => $match->evidence,
                ];
            }

            $answer->save();

            return $answer;
        });

        $this->delivery->deliver($answer);

        return $answer;
    }

    /**
     * Код, которого нет среди непривязанных ответов терапевта. Истёкшие ответы удаляются раз в час,
     * до того их коды тоже заняты: кодов 10 000, ответов за полдня — десятки.
     */
    private function freeCode(User $therapist): string
    {
        for ($attempt = 0; $attempt < self::CODE_ATTEMPTS; $attempt++) {
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

            $taken = QueueAnswer::query()
                ->where('doctor_id', $therapist->id)
                ->where('code', $code)
                ->whereNull('attached_at')
                ->exists();

            if (! $taken) {
                return $code;
            }
        }

        throw new RuntimeException('Не удалось подобрать свободный код ответов из очереди.');
    }
}
