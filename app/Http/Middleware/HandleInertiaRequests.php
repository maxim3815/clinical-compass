<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\PatientSignals;
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
            // Открытый ключ VAPID: с ним браузер подписывается на пуши (страница «Уведомления»).
            'vapidPublicKey' => fn (): ?string => $request->user() === null ? null : config('webpush.vapid.public_key'),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
