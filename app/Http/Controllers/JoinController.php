<?php

namespace App\Http\Controllers;

use App\Actions\EnterAppointmentPin;
use App\Exceptions\AccessLinkUnavailable;
use App\Http\Controllers\Concerns\RendersAccessLinkPages;
use App\Http\Requests\JoinPinRequest;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Support\PinAttempts;
use App\Support\Plural;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Подключение к врачу на приёме: QR ведёт на адрес врача, пациент вводит PIN, который назвал
 * врач, и попадает в обычную форму регистрации по приглашению.
 */
class JoinController extends Controller
{
    use RendersAccessLinkPages;

    public const string WRONG_PIN = 'Код не подошёл. Наберите 4 цифры ещё раз или попросите врача назвать код снова.';

    public function show(Request $request, string $joinCode): Response
    {
        if ($request->user() !== null) {
            return $this->signedIn($request);
        }

        $doctor = $this->doctor($joinCode);

        if ($doctor === null) {
            return $this->invalidLink($request);
        }

        $lockedFor = $this->attempts($request)->lockedFor();

        return Inertia::render('join/Show', [
            'joinCode' => $doctor->doctorProfile?->join_code,
            'doctor' => [
                'name' => $doctor->name,
                'specialty' => $doctor->doctorProfile?->specialty,
            ],
            'locked' => $lockedFor > 0 ? self::lockedMessage($lockedFor) : null,
        ])->toResponse($request);
    }

    public function store(JoinPinRequest $request, EnterAppointmentPin $enterAppointmentPin, string $joinCode): RedirectResponse
    {
        $doctor = $this->doctor($joinCode);

        if ($doctor === null) {
            return to_route('join.show', $joinCode);
        }

        $attempts = $this->attempts($request);
        $lockedFor = $attempts->lockedFor();

        if ($lockedFor > 0) {
            return to_route('join.show', $joinCode)->withErrors(['pin' => self::lockedMessage($lockedFor)]);
        }

        try {
            $issued = $enterAppointmentPin->handle($doctor, $request->pin());
        } catch (AccessLinkUnavailable) {
            // Неверный, истёкший, погашенный и использованный PIN выглядят одинаково.
            $message = $attempts->fail()
                ? self::lockedMessage(PinAttempts::LOCK_SECONDS)
                : self::WRONG_PIN;

            return to_route('join.show', $joinCode)->withErrors(['pin' => $message]);
        }

        return to_route('invitation.show', $issued->token);
    }

    private function doctor(string $joinCode): ?User
    {
        // Адрес диктуют вслух: заглавные буквы, набранные пациентом, тоже подходят.
        return DoctorProfile::query()
            ->where('join_code', Str::lower($joinCode))
            ->first()
            ?->user;
    }

    private function attempts(Request $request): PinAttempts
    {
        return new PinAttempts((string) $request->ip());
    }

    private static function lockedMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        // 15 минут — это весь приём: подсказываем запасной путь.
        return "Слишком много неверных попыток. Попробуйте снова через {$minutes} ".Plural::choose($minutes, ['минуту', 'минуты', 'минут'])
            .' или попросите врача прислать ссылку-приглашение.';
    }
}
