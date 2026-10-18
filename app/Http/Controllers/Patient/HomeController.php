<?php

namespace App\Http\Controllers\Patient;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $patient */
        $patient = $request->user();

        $doctor = $patient->currentDoctor()->with('doctorProfile')->first();

        return Inertia::render('patient/Home', [
            'doctor' => $doctor === null ? null : [
                'name' => $doctor->name,
                'specialty' => $doctor->doctorProfile?->specialty,
            ],
        ]);
    }
}
