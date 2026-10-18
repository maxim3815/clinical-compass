<?php

namespace App\Http\Controllers\Settings;

use App\Enums\RussianTimezone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->patientProfile;

        // Дата рождения и часовой пояс есть только у пациента.
        return Inertia::render('settings/Profile', [
            'patientProfile' => $profile === null ? null : [
                'birth_date' => $profile->birth_date?->toDateString(),
                'timezone' => $profile->timezone->value,
            ],
            'timezones' => $profile === null ? [] : RussianTimezone::options(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        DB::transaction(function () use ($user, $data): void {
            $user->update(Arr::only($data, ['name']));
            $user->patientProfile?->update(Arr::only($data, ['birth_date', 'timezone']));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
