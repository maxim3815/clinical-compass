<?php

use App\Actions\SubmitAssessments;
use App\Models\CareRelationship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadScales();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 19:00', 'Europe/Moscow'));
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['started_at' => now()]);
});

test('doctor sees the last sums with band and change, the chart and every answer', function () {
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: 2, phq9: 2, phq9Item9: 0));
    $this->travelTo(CarbonImmutable::parse('2026-10-08 19:30', 'Europe/Moscow'));
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: 1, phq9: 1, phq9Item9: 2));
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00', 'Europe/Moscow'));

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('wellbeing.status', ['due' => false, 'due_since' => null, 'last' => '08.10.2026', 'next' => '15.10.2026'])
            ->where('wellbeing.scales.0.name', 'GAD-7')
            ->where('wellbeing.scales.0.latest', ['total' => 7, 'band' => 'лёгкая тревога', 'date' => '08.10.2026'])
            ->where('wellbeing.scales.0.previous', ['total' => 14, 'date' => '01.10.2026'])
            // С начала считается, когда заполнений больше двух.
            ->where('wellbeing.scales.0.first', null)
            ->where('wellbeing.scales.0.thresholds', [5, 10, 15])
            ->where('wellbeing.scales.1.latest', ['total' => 10, 'band' => 'умеренная депрессия', 'date' => '08.10.2026'])
            ->where('wellbeing.scales.1.thresholds', [5, 10, 15, 20])
            ->has('wellbeing.scales.1.points', 2)
            ->where('wellbeing.scales.1.points.0.total', 16)
            ->has('wellbeing.fillings', 2)
            // Сначала последнее заполнение, в нём ответ на 9-й вопрос PHQ-9.
            ->where('wellbeing.fillings.0.submitted_at', '08.10.2026 19:30')
            ->where('wellbeing.fillings.0.assessments.1.name', 'PHQ-9')
            ->where('wellbeing.fillings.0.assessments.1.answers.8', [
                'item' => 9,
                'text' => 'Мысли о том, что мне бы хотелось умереть или причинить себе боль',
                'score' => 2,
                'label' => 'Более, чем половину этого времени',
                'highlight' => true,
            ])
            ->where('wellbeing.fillings.0.assessments.0.answers.0.highlight', false)
            ->where('wellbeing.fillings.1.assessments.0.total', 14));
});

test('doctor sees that the patient has not answered yet', function () {
    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('wellbeing.status.due', true)
            ->where('wellbeing.status.due_since', '01.10.2026')
            ->where('wellbeing.fillings', [])
            ->where('wellbeing.scales.0.latest', null)
            ->where('wellbeing.scales.0.points', []));
});

test('another doctor does not see the questionnaires of the patient', function () {
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers());

    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('doctor.patients.show', $this->patient))
        ->assertNotFound();
});
