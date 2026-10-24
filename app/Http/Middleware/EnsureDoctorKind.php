<?php

namespace App\Http\Middleware;

use App\Enums\DoctorKind;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пропускает только врачей указанного вида: doctor.kind:psychiatrist, doctor.kind:therapist.
 * Чужой раздел отвечает 404, как чужой пациент: кабинет терапевта просто не знает этих адресов.
 */
class EnsureDoctorKind
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->doctorKind() === DoctorKind::from($kind), 404);

        return $next($request);
    }
}
