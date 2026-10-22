<?php

use App\Models\Assessment;
use App\Models\CareRelationship;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadScales();
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00', 'Europe/Moscow'));
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    // Наблюдение началось сегодня: срок дня 0 наступил.
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['started_at' => now()]);
});

test('new patient sees the questionnaires on today until they are filled', function () {
    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('assessments', ['due' => true, 'due_since' => '22 октября', 'last' => null, 'next' => '29 октября']));

    $this->post(route('patient.questionnaires.store'), ['answers' => assessmentAnswers()])
        ->assertRedirect();

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('assessments', ['due' => false, 'due_since' => null, 'last' => '22 октября', 'next' => '29 октября']));

    // Через неделю — следующий срок.
    $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00', 'Europe/Moscow'));

    $this->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('assessments.due', true)->where('assessments.due_since', '29 октября'));
});

test('patient sends both questionnaires and sees that the doctor got the answers', function () {
    $answers = [
        'gad7' => [0, 1, 2, 3, 0, 1, 2],
        'phq9' => [3, 3, 3, 3, 3, 3, 3, 3, 0],
    ];

    $response = $this->actingAs($this->patient)
        ->post(route('patient.questionnaires.store'), ['answers' => $answers]);

    $assessments = Assessment::query()->with(['scale', 'answers'])->orderBy('id')->get();
    $response->assertRedirect(route('patient.questionnaires.sent', $assessments[0]));

    expect($assessments)->toHaveCount(2)
        ->and($assessments->map(fn (Assessment $assessment): array => [$assessment->scale->code, $assessment->total, $assessment->band])->all())
        ->toBe([['gad7', 9, 'лёгкая тревога'], ['phq9', 24, 'тяжёлая депрессия']])
        ->and($assessments[0]->answers->pluck('score')->all())->toBe($answers['gad7'])
        ->and($assessments[1]->answers->pluck('item')->all())->toBe(range(1, 9))
        ->and($assessments[0]->submitted_at->eq($assessments[1]->submitted_at))->toBeTrue()
        ->and($assessments[0]->local_date->toDateString())->toBe('2026-10-22')
        ->and($assessments[0]->patient_id)->toBe($this->patient->id)
        ->and($assessments[0]->scale_version)->toBe($assessments[0]->scale->version);

    $this->get(route('patient.questionnaires.sent', $assessments[0]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/QuestionnaireSent')
            ->where('doctor', 'Смирнова Анна Сергеевна')
            ->where('submittedAt', '22 октября в 12:00'));
});

test('questionnaires are not sent without an answer to every question', function (array $answers, string $error) {
    $this->actingAs($this->patient)
        ->from(route('patient.questionnaires.create'))
        ->post(route('patient.questionnaires.store'), ['answers' => $answers])
        ->assertRedirect(route('patient.questionnaires.create'))
        ->assertSessionHasErrors($error);

    expect(Assessment::query()->count())->toBe(0);
})->with([
    'unanswered question' => [['gad7' => [0, 1, 2, null, 0, 1, 2], 'phq9' => array_fill(0, 9, 0)], 'answers.gad7.3'],
    'missing question' => [['gad7' => [0, 1, 2, 3, 0, 1], 'phq9' => array_fill(0, 9, 0)], 'answers.gad7'],
    'unknown score' => [['gad7' => array_fill(0, 7, 1), 'phq9' => [...array_fill(0, 8, 0), 4]], 'answers.phq9.8'],
    'one questionnaire only' => [['gad7' => array_fill(0, 7, 1)], 'answers.phq9'],
]);

test('validation message names the unanswered question', function () {
    $this->actingAs($this->patient)
        ->post(route('patient.questionnaires.store'), ['answers' => ['gad7' => [0, 1, 2, null, 0, 1, 2], 'phq9' => array_fill(0, 9, 0)]])
        ->assertSessionHasErrors(['answers.gad7.3' => 'Ответьте на вопрос 4.']);
});

test('filled questionnaire can not be changed or deleted', function (Closure $change) {
    $this->actingAs($this->patient)->post(route('patient.questionnaires.store'), ['answers' => assessmentAnswers()]);
    $assessment = Assessment::query()->with('answers')->firstOrFail();

    $change($assessment);
})->with([
    'change total' => fn (Assessment $assessment) => $assessment->update(['total' => 0]),
    'delete' => fn (Assessment $assessment) => $assessment->delete(),
    'change answer' => fn (Assessment $assessment) => $assessment->answers->firstOrFail()->update(['score' => 0]),
])->throws(QueryException::class, 'is append-only');

test('patient sees only own sent screen', function () {
    $other = User::factory()->patientOf($this->doctor)->create();
    $this->actingAs($other)->post(route('patient.questionnaires.store'), ['answers' => assessmentAnswers()]);
    $foreign = Assessment::query()->firstOrFail();

    $this->actingAs($this->patient)
        ->get(route('patient.questionnaires.sent', $foreign))
        ->assertNotFound();
});

test('patient without a doctor is not offered the questionnaires', function () {
    $alone = User::factory()->patient()->create();

    $this->actingAs($alone)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('assessments', null));

    $this->get(route('patient.questionnaires.create'))->assertRedirect(route('patient.wellbeing'));
    $this->post(route('patient.questionnaires.store'), ['answers' => assessmentAnswers()])->assertNotFound();

    expect(Assessment::query()->count())->toBe(0);
});

test('questionnaire page gives questions, options and the source', function () {
    $this->actingAs($this->patient)
        ->get(route('patient.questionnaires.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Questionnaire')
            ->has('scales', 2)
            ->where('scales.0.code', 'gad7')
            ->where('scales.0.title', 'Беспокойство')
            ->has('scales.0.items', 7)
            ->where('scales.1.items.8', 'Мысли о том, что мне бы хотелось умереть или причинить себе боль')
            ->where('scales.1.options.0', ['score' => 0, 'label' => 'Совсем нет'])
            ->where('scales.1.source.url', 'https://doi.org/10.17759/cpse.2023120406')
            ->has('scales.1.source.license'));
});

test('wellbeing shows sums by date without bands', function () {
    $this->actingAs($this->patient)->post(route('patient.questionnaires.store'), ['answers' => assessmentAnswers(gad7: 2)]);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 19:00', 'Europe/Moscow'));
    $this->post(route('patient.questionnaires.store'), ['answers' => assessmentAnswers(gad7: 1)]);

    $this->get(route('patient.wellbeing'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Wellbeing')
            ->where('status.due', false)
            ->where('scales.0.title', 'Беспокойство')
            ->where('scales.0.max', 21)
            ->where('scales.0.points.0.total', 14)
            ->where('scales.0.points.0.date', '22 октября')
            ->where('scales.0.points.1.total', 7)
            ->where('scales.0.points.1.iso', '2026-10-29')
            ->missing('scales.0.points.0.band')
            ->missing('scales.0.bands'));
});

test('doctor can not open the patient questionnaire pages', function () {
    $this->actingAs($this->doctor)
        ->get(route('patient.questionnaires.create'))
        ->assertForbidden();
});
