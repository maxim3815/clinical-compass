<?php

namespace App\Http\Controllers\Patient;

use App\Actions\RecordHelpEvent;
use App\Actions\RequestHelp;
use App\Enums\AlertTrigger;
use App\Enums\HelpEventType;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Экран помощи пациента: 112, кризисная линия, «Сообщить врачу» и «Написать врачу». Открытие
 * экрана и нажатия на звонки врач видит тихими строками в карточке, «Сообщить врачу» — алерт.
 */
class HelpController extends Controller
{
    public function show(Request $request, RecordHelpEvent $recordHelpEvent): Response
    {
        /** @var User $patient */
        $patient = $request->user();
        $doctor = $patient->currentDoctor()->first();

        $recordHelpEvent->handle($patient, HelpEventType::Opened);

        return Inertia::render('patient/Help', [
            // Лечащий врач: «Написать врачу» и «Сообщить врачу» есть, только если он есть.
            'doctor' => $doctor?->name,
            'canReport' => RequestHelp::available($patient),
            'lastReport' => self::lastReport($patient),
        ]);
    }

    /**
     * «Сообщить врачу»: алерт лечащему врачу без подтверждения. Пациент видит время отправки.
     */
    public function report(Request $request, RequestHelp $requestHelp): RedirectResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        // Врача нет или правило по кнопке выключено: экран сам скажет, что сообщение не ушло.
        if ($requestHelp->handle($patient) === null) {
            Inertia::flash('helpReportRefused', true);

            return to_route('patient.help.show');
        }

        Inertia::flash('helpReportedAt', CarbonImmutable::now()->setTimezone($patient->localTimezone())->format('H:i'));

        return to_route('patient.help.show');
    }

    /**
     * Пациент нажал звонок в 112 или на кризисную линию. Сам звонок совершает телефон.
     */
    public function call(Request $request, RecordHelpEvent $recordHelpEvent): HttpResponse
    {
        /** @var User $patient */
        $patient = $request->user();

        $validated = $request->validate([
            'line' => ['required', Rule::in(['112', 'crisis_line'])],
        ]);

        $recordHelpEvent->handle($patient, $validated['line'] === '112' ? HelpEventType::Call112 : HelpEventType::CallCrisisLine);

        return response()->noContent();
    }

    /**
     * Когда пациент последний раз нажимал «Сообщить врачу», если недавно: «сегодня в 14:52».
     * Это его собственное действие, а не статус алерта: строка не пропадает, когда врач закрыл
     * алерт, поэтому ничего о решении врача не говорит.
     */
    private static function lastReport(User $patient): ?string
    {
        $last = Alert::query()
            ->where('patient_id', $patient->id)
            ->whereIn('alert_rule_id', AlertRule::query()->triggeredBy(AlertTrigger::HelpRequest)->select('id'))
            ->get(['submitted_at', 'repeated_at'])
            ->map(fn (Alert $alert): CarbonImmutable => $alert->repeated_at ?? $alert->submitted_at)
            ->max();

        if ($last === null || $last->lessThan(CarbonImmutable::now()->subHours((int) config('help.reported_hours')))) {
            return null;
        }

        return self::moment($patient, $last);
    }

    /**
     * Время по часовому поясу пациента: «сегодня в 14:52», «вчера в 23:10».
     */
    private static function moment(User $patient, CarbonImmutable $time): string
    {
        $local = $time->setTimezone($patient->localTimezone());
        $day = $local->isSameDay(CarbonImmutable::now($patient->localTimezone())) ? 'сегодня' : 'вчера';

        return "{$day} в {$local->format('H:i')}";
    }
}
