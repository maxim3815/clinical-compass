<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\Alerts\AlertPresenter;
use App\Support\PatientSignals;
use App\Support\QueueAnswers\QueueAnswerPresenter;
use App\Support\UnreadMessages;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'login', 'role']),
            ],
            // Пациентке кнопки отметки приёма пишутся в женском роде: «Приняла», «Не приняла».
            'femalePatient' => fn (): bool => (bool) $request->user()?->isFemalePatient(),
            // Счётчик в пункте меню чата. Дальше его обновляют события в реальном времени.
            'unreadMessages' => fn (): int => $request->user() === null ? 0 : UnreadMessages::count($request->user()),
            // Счётчик у пункта меню «Пациенты» есть только у врача: пропуски приёма и противопоказания без решения.
            'patientSignals' => fn (): int => $request->user()?->role === UserRole::Doctor ? PatientSignals::count($request->user()) : 0,
            // Непринятые алерты врача: красная плашка на всех страницах кабинета. Дальше — событиями.
            'urgentAlerts' => fn (): array => $request->user()?->role === UserRole::Doctor ? AlertPresenter::banner($request->user()) : [],
            // Ответы из очереди с сигналом по 9-му вопросу, которые терапевт ещё не открыл: в той же
            // красной плашке. Дальше — событиями.
            'urgentQueue' => fn (): array => $request->user()?->role === UserRole::Doctor ? QueueAnswerPresenter::banner($request->user()) : [],
            // Вид врача: у терапевта своё меню и свой кабинет. У пациента null.
            'doctorKind' => fn (): ?string => $request->user()?->doctorKind()?->value,
            // Пациента ведёт терапевт: чата и окна побочных эффектов нет, ссылки на них не показываются.
            'withTherapist' => fn (): bool => (bool) $request->user()?->isWithTherapist(),
            // Кризисная линия на экране помощи пациента и в карточке «Если вам сейчас тяжело». Гостю —
            // на странице опросников у двери терапевта.
            'crisisLine' => fn (): ?array => $request->user()?->role !== UserRole::Doctor ? [
                'label' => (string) config('help.crisis_line'),
                'href' => 'tel:'.preg_replace('/\D/', '', (string) config('help.crisis_line')),
            ] : null,
            // Открытый ключ VAPID: с ним браузер подписывается на пуши (страница «Уведомления»).
            'vapidPublicKey' => fn (): ?string => $request->user() === null ? null : config('webpush.vapid.public_key'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
