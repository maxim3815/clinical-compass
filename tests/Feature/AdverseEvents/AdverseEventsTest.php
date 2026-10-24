<?php

use App\Enums\AdverseEventSeverity;
use App\Enums\AlertDecision;
use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionCancelReason;
use App\Enums\PrescriptionCloseReason;
use App\Enums\SideEffectLevel;
use App\Events\PatientSignalsChanged;
use App\Models\AdverseEvent;
use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\CareRelationship;
use App\Models\Prescription;
use App\Models\SideEffect;
use App\Models\User;
use App\Notifications\UrgentAlert;
use App\Support\AdverseEvents\SideEffectCatalog;
use App\Support\PatientSignals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    loadSafetyRules();
    loadAlertRules();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 14:05:00', 'Europe/Moscow'));
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Кузнецова Елена Викторовна']);
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['started_at' => now()->subMonth()]);

    // «Авиандр» с 12 октября: 24 октября — 13-й день курса.
    $this->aviandr = takes($this->patient, 'maritupirdine');
    $this->aviandr->update(['starts_on' => '2026-10-12', 'prescriber_id' => $this->doctor->id]);

    $this->symptom = fn (string $code): int => SideEffect::query()->where('code', $code)->value('id');
    $this->report = fn (array $data, ?User $patient = null) => $this->actingAs($patient ?? $this->patient)->post(route('patient.side-effects.store'), [
        'severity' => 'moderate',
        'started' => 'yesterday',
        ...$data,
    ]);
});

test('the catalog loads with the drugs and repeated import changes nothing', function () {
    $trismus = SideEffect::query()->where('code', 'trismus')->sole();

    expect(SideEffect::query()->count())->toBe(39)
        ->and($trismus->name)->toBe('Сводит челюсти, трудно открыть рот')
        ->and($trismus->term)->toBe('Тризм')
        ->and($trismus->drugs()->sole()->level)->toBe(SideEffectLevel::Urgent);

    $this->artisan('app:drugs:import')->assertSuccessful()->expectsOutputToContain('Побочные эффекты не изменились. Симптомов: 39, препаратов: 5.');
});

test('a broken catalog file is rejected whole and the old list stays', function () {
    $file = (string) tempnam(sys_get_temp_dir(), 'side-effects');
    file_put_contents($file, <<<'YAML'
        symptoms:
          - { code: drowsiness, name: Сонливость, term: Сонливость }
        drugs:
          maritupirdine:
            - level: common
              instruction: Прекратите приём.
              symptoms: [drowsiness, headache]
          unknown:
            - level: urgent
              symptoms: [drowsiness]
        YAML);

    // Список ошибок выводится одним блоком: проверяем вывод целиком.
    expect(Artisan::call('app:drugs:import', ['--side-effects' => $file]))->toBe(1)
        ->and(Artisan::output())
        ->toContain('Препарат maritupirdine, группа 1: Значение поля instruction запрещено')
        ->toContain('симптома headache нет в разделе symptoms')
        ->toContain('Препарат unknown: такого препарата нет в справочнике')
        ->and(SideEffect::query()->count())->toBe(39);
});

test('the patient list joins the leaflets of current drugs and takes the most urgent group', function () {
    takes($this->patient, 'escitalopram', own: true);

    $catalog = SideEffectCatalog::forPatient($this->patient);
    $byCode = collect($catalog)->keyBy(fn (array $entry): string => SideEffect::query()->whereKey($entry['id'])->value('code'));

    // Потливость у эсциталопрама частая, а у «Авиандра» — «сообщить врачу сразу».
    expect($byCode['sweating']['level'])->toBe(SideEffectLevel::Urgent)
        ->and($byCode['face_swelling']['level'])->toBe(SideEffectLevel::Emergency)
        ->and($byCode['face_swelling']['instructions'])->toBe([[
            'drug' => 'Эсциталопрам',
            'text' => 'Немедленно прекратите приём препарата и обратитесь за помощью в ближайшее медицинское учреждение.',
            'url' => 'https://grls.minzdrav.gov.ru/InstrImg/2025/10/31/1523077/f3b1f4c4-b635-4e9b-9a49-9cca43b48fd4.pdf',
        ]])
        ->and($byCode)->not->toHaveKey('hives');

    $this->actingAs($this->patient)
        ->get(route('patient.side-effects.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/SideEffectForm')
            ->where('groups.0.level', 'emergency')
            ->where('groups.0.label', 'Нужна помощь сейчас: сразу звоните 112')
            ->where('groups.0.symptoms.0.code', 'face_swelling')
            ->where('groups.1.label', 'Бывает часто')
            ->where('groups.1.symptoms.0.name', 'Сонливость')
            ->where('groups.3.level', 'urgent')
            ->where('groups.3.label', 'Врач получит срочное уведомление')
            ->where('today', '2026-10-24')
            ->where('minDate', '2026-07-26'));
});

test('a regular symptom waits for the doctor in the queue without an alert or a push', function () {
    Notification::fake();
    Event::fake([PatientSignalsChanged::class]);
    subscribeToPush($this->doctor);

    ($this->report)(['symptoms' => [($this->symptom)('drowsiness')], 'severity' => 'severe'])
        ->assertRedirect(route('patient.side-effects.index'))
        ->assertInertiaFlash('sideEffectReport', ['kind' => 'regular', 'at' => '14:05', 'instructions' => []]);

    $event = AdverseEvent::query()->sole();

    expect($event->name)->toBe('Сонливость')
        ->and($event->level)->toBe(SideEffectLevel::Common)
        ->and($event->severity)->toBe(AdverseEventSeverity::Severe)
        ->and($event->started_on->toDateString())->toBe('2026-10-23')
        ->and($event->reported_by_id)->toBe($this->patient->id)
        ->and($event->alert_id)->toBeNull()
        ->and(Alert::query()->count())->toBe(0)
        ->and(PatientSignals::count($this->doctor))->toBe(1);

    Notification::assertNothingSent();
    Event::assertDispatched(PatientSignalsChanged::class, fn (PatientSignalsChanged $sent): bool => $sent->doctor->is($this->doctor));

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.name', 'Кузнецова Елена Викторовна')
            ->where('attention.0.adverse_events', ['Сонливость, сильно']));

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('adverseEvents.0.term', 'Сонливость')
            ->where('adverseEvents.0.started_on', '23.10.2026')
            ->where('adverseEvents.0.medications', [['drug' => 'Авиандр', 'day' => 12]])
            ->where('adverseEvents.0.awaits_review', true)
            ->where('adverseEvents.0.by_doctor', null));

    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.adverse-events.review', ['patient' => $this->patient, 'adverseEvent' => $event]))
        ->assertRedirect();

    expect($event->refresh()->reviewed_by_id)->toBe($this->doctor->id)
        ->and(PatientSignals::count($this->doctor))->toBe(0)
        ->and(AuditLog::query()->where('action', 'adverse_event.reviewed')->sole()->subject_id)->toBe($event->id);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('attention', []));
});

test('trismus on Aviandr raises an alert, the patient sees «sent urgently» and closing the alert reviews it', function () {
    Notification::fake();
    subscribeToPush($this->doctor);

    ($this->report)(['symptoms' => [($this->symptom)('trismus'), ($this->symptom)('headache')]])
        ->assertInertiaFlash('sideEffectReport', ['kind' => 'urgent', 'at' => '14:05', 'instructions' => []]);

    $alert = Alert::query()->sole();
    $trismus = AdverseEvent::query()->where('term', 'Тризм')->sole();
    $headache = AdverseEvent::query()->where('term', 'Головная боль')->sole();

    expect($alert->rule->code)->toBe('adverse_event')
        ->and($alert->doctor_id)->toBe($this->doctor->id)
        ->and($alert->evidence)->toBe([['type' => 'adverse_event', 'name' => 'Сводит челюсти, трудно открыть рот', 'term' => 'Тризм', 'level' => 'urgent']])
        ->and($alert->trigger())->toBe('тризм')
        ->and($alert->isHelpRequest())->toBeFalse()
        ->and($trismus->alert_id)->toBe($alert->id)
        ->and($headache->alert_id)->toBeNull();

    Notification::assertSentTo($this->doctor, UrgentAlert::class, function (UrgentAlert $notification): bool {
        $push = $notification->toWebPush($this->doctor)->toArray();

        return $push['title'] === 'Срочно: Кузнецова Е. В.' && $push['body'] === 'Сообщение о побочном эффекте';
    });

    // На «Сегодня» — своя карточка, а не карточка с кризисной линией.
    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('help', false)
            ->where('sideEffectAlert', ['symptoms' => ['Сводит челюсти, трудно открыть рот'], 'at' => "сегодня в\u{00A0}14:05", 'emergency' => false]));

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('alerts.0.kind', 'adverse_event')
            ->where('alerts.0.symptoms', ['Тризм: «Сводит челюсти, трудно открыть рот»'])
            ->where('adverseEvents.1.alert_open', true)
            ->where('adverseEvents.1.awaits_review', false));

    // Срочная запись разбирается решением по алерту, а не «Просмотрено».
    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.adverse-events.review', ['patient' => $this->patient, 'adverseEvent' => $trismus]))
        ->assertInertiaFlash('toast.type', 'info');

    expect($trismus->refresh()->reviewed_at)->toBeNull();

    // Когда пациентка отметила, что всё прошло, карточка на «Сегодня» больше не нужна.
    $this->actingAs($this->patient)->post(route('patient.side-effects.resolve', $trismus));
    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('sideEffectAlert', null));

    $this->actingAs($this->doctor)->post(route('doctor.patients.alerts.close', ['patient' => $this->patient, 'alert' => $alert]), [
        'decision' => AlertDecision::Contacted->value,
    ]);

    expect($trismus->refresh()->reviewed_by_id)->toBe($this->doctor->id)
        ->and($headache->refresh()->reviewed_at)->toBeNull();
});

test('a new urgent symptom goes into the open alert and raises it again', function () {
    Notification::fake();
    subscribeToPush($this->doctor);

    ($this->report)(['symptoms' => [($this->symptom)('trismus')]]);
    $alert = Alert::query()->sole();
    $this->actingAs($this->doctor)->post(route('doctor.patients.alerts.acknowledge', ['patient' => $this->patient, 'alert' => $alert]));

    $this->travel(30)->seconds();
    ($this->report)(['symptoms' => [($this->symptom)('trismus'), ($this->symptom)('muscle_stiffness')]]);

    $alert->refresh();

    expect(Alert::query()->count())->toBe(1)
        ->and($alert->repeat_count)->toBe(1)
        ->and($alert->awaitsAcknowledgement())->toBeTrue()
        ->and($alert->trigger())->toBe('тризм, мышечная скованность')
        ->and(AdverseEvent::query()->where('alert_id', $alert->id)->count())->toBe(3);

    Notification::assertSentToTimes($this->doctor, UrgentAlert::class, 2);
    Notification::assertSentTo($this->doctor, UrgentAlert::class, fn (UrgentAlert $notification): bool => $notification->repeat
        && $notification->toWebPush($this->doctor)->toArray()['body'] === 'Повторно: сообщение о побочном эффекте');
});

test('an emergency symptom shows 112 first with the leaflet words of every drug that names it', function () {
    takes($this->patient, 'escitalopram', own: true);
    takes($this->patient, 'hydroxyzine', own: true);

    ($this->report)(['symptoms' => [($this->symptom)('face_swelling')], 'started' => 'today'])
        ->assertInertiaFlash('sideEffectReport.kind', 'emergency')
        ->assertInertiaFlash('sideEffectReport.instructions.0.drug', 'Эсциталопрам')
        ->assertInertiaFlash('sideEffectReport.instructions.1.drug', 'Гидроксизин')
        ->assertInertiaFlash('sideEffectReport.instructions.1.text', 'Прекратите приём препарата и немедленно обратитесь за медицинской помощью.');

    expect(Alert::query()->sole()->evidence[0]['level'])->toBe('emergency');

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('sideEffectAlert.emergency', true));

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('alerts.0.symptoms', ['Ангионевротический отёк или анафилаксия: «Отекло лицо, губы или язык, трудно дышать или глотать», пациенту показано «Позвоните 112»']));
});

test('with an emergency symptom the patient may skip the severity and the start', function () {
    takes($this->patient, 'escitalopram', own: true);

    $this->actingAs($this->patient)
        ->post(route('patient.side-effects.store'), ['symptoms' => [($this->symptom)('seizures')]])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('sideEffectReport.kind', 'emergency');

    $event = AdverseEvent::query()->sole();

    expect($event->severity)->toBe(AdverseEventSeverity::Severe)
        ->and($event->started_on->toDateString())->toBe('2026-10-24');
});

test('the severity and the start stay required when an emergency symptom comes with another one', function () {
    takes($this->patient, 'escitalopram', own: true);

    $this->actingAs($this->patient)
        ->post(route('patient.side-effects.store'), ['symptoms' => [($this->symptom)('seizures'), ($this->symptom)('drowsiness')]])
        ->assertSessionHasErrors(['severity', 'started']);

    expect(AdverseEvent::query()->count())->toBe(0);
});

test('the Today card lists only symptoms that have not passed', function () {
    takes($this->patient, 'escitalopram', own: true);
    ($this->report)(['symptoms' => [($this->symptom)('trismus')]]);
    ($this->report)(['symptoms' => [($this->symptom)('face_swelling')], 'started' => 'today']);

    $swelling = AdverseEvent::query()->where('side_effect_id', ($this->symptom)('face_swelling'))->sole();
    $this->actingAs($this->patient)->post(route('patient.side-effects.resolve', $swelling));

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sideEffectAlert.symptoms', ['Сводит челюсти, трудно открыть рот'])
            ->where('sideEffectAlert.emergency', false));
});

test('a later emergency symptom comes first in the alert', function () {
    takes($this->patient, 'escitalopram', own: true);

    ($this->report)(['symptoms' => [($this->symptom)('trismus')]]);
    ($this->report)(['symptoms' => [($this->symptom)('face_swelling')]]);

    expect(Alert::query()->sole()->trigger())->toBe('ангионевротический отёк или анафилаксия, тризм');
});

test('without an active rule an urgent symptom still reaches the doctor through the queue', function () {
    AlertRule::query()->where('code', 'adverse_event')->update(['is_active' => false]);

    ($this->report)(['symptoms' => [($this->symptom)('trismus')]])
        ->assertInertiaFlash('sideEffectReport.kind', 'regular');

    expect(Alert::query()->count())->toBe(0)
        ->and(PatientSignals::count($this->doctor))->toBe(1);
});

test('«other» in the patient’s own words is recorded without an alert', function () {
    ($this->report)(['other' => '  Шумит в ушах  ', 'severity' => 'mild']);

    $event = AdverseEvent::query()->sole();

    expect($event->name)->toBe('Шумит в ушах')
        ->and($event->term)->toBeNull()
        ->and($event->level)->toBeNull()
        ->and(Alert::query()->count())->toBe(0);
});

test('the form is validated', function (array $data, string $field, string $message) {
    ($this->report)($data)->assertSessionHasErrors([$field => $message]);

    expect(AdverseEvent::query()->count())->toBe(0);
})->with([
    'nothing chosen' => [['symptoms' => []], 'symptoms', 'Выберите, что беспокоит, или напишите своими словами.'],
    'symptom of another drug' => [['symptoms' => [999999]], 'symptoms.0', 'Список изменился. Выберите, что беспокоит, ещё раз.'],
    'no severity' => [['other' => 'Шум', 'severity' => ''], 'severity', 'Выберите, насколько сильно.'],
    'no start' => [['other' => 'Шум', 'started' => ''], 'started', 'Выберите, когда началось.'],
    'earlier without a date' => [['other' => 'Шум', 'started' => 'earlier'], 'started_on', 'Укажите дату.'],
    'a future date' => [['other' => 'Шум', 'started' => 'earlier', 'started_on' => '2026-10-25'], 'started_on', 'Дата не позже сегодняшней.'],
    'too long ago' => [['other' => 'Шум', 'started' => 'earlier', 'started_on' => '2026-07-01'], 'started_on', 'Дата не раньше чем 90 дней назад.'],
    'too long text' => [['other' => str_repeat('а', 201)], 'other', 'Текст длиннее 200 символов. Сократите его.'],
]);

test('an earlier date is taken as the patient entered it', function () {
    ($this->report)(['other' => 'Шум', 'started' => 'earlier', 'started_on' => '2026-10-15']);

    expect(AdverseEvent::query()->sole()->started_on->toDateString())->toBe('2026-10-15');
});

test('a patient without a doctor gets no form', function () {
    CareRelationship::query()->where('patient_id', $this->patient->id)->update(['ended_at' => now()]);

    $this->actingAs($this->patient)->get(route('patient.side-effects.create'))->assertRedirect(route('patient.side-effects.index'));
    $this->actingAs($this->patient)
        ->get(route('patient.side-effects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canReport', false));

    ($this->report)(['other' => 'Шум'])->assertRedirect(route('patient.side-effects.index'));

    expect(AdverseEvent::query()->count())->toBe(0);
});

test('«it passed» gives the doctor the duration and the patient sees it as passed', function () {
    ($this->report)(['symptoms' => [($this->symptom)('drowsiness')], 'started' => 'earlier', 'started_on' => '2026-10-20']);
    $event = AdverseEvent::query()->sole();

    $this->actingAs($this->patient)
        ->get(route('patient.side-effects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.0.period', 'с 20 октября')
            ->where('reports.0.severity', 'мешает')
            ->where('reports.0.resolved', false));

    $this->actingAs($this->patient)->post(route('patient.side-effects.resolve', $event))->assertRedirect(route('patient.side-effects.index'));
    $this->actingAs($this->patient)->post(route('patient.side-effects.resolve', $event));

    expect($event->refresh()->resolved_on->toDateString())->toBe('2026-10-24');

    $this->actingAs($this->patient)
        ->get(route('patient.side-effects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.0.period', '20–24 октября')
            ->where('reports.0.resolved', true));

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page->where('adverseEvents.0.resolved', ['on' => '24.10.2026', 'days' => 5]));
});

test('an accidental «it passed» is undone the same day, but not later', function () {
    ($this->report)(['symptoms' => [($this->symptom)('drowsiness')]]);
    $event = AdverseEvent::query()->sole();

    $this->actingAs($this->patient)->post(route('patient.side-effects.resolve', $event));
    $this->actingAs($this->patient)
        ->get(route('patient.side-effects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('reports.0.undoable', true));

    $this->actingAs($this->patient)->delete(route('patient.side-effects.unresolve', $event))->assertRedirect(route('patient.side-effects.index'));
    expect($event->refresh()->resolved_on)->toBeNull();

    $this->actingAs($this->patient)->post(route('patient.side-effects.resolve', $event));
    $this->travel(1)->day();

    $this->actingAs($this->patient)
        ->get(route('patient.side-effects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('reports.0.undoable', false));
    $this->actingAs($this->patient)->delete(route('patient.side-effects.unresolve', $event));

    expect($event->refresh()->resolved_on?->toDateString())->toBe('2026-10-24');
});

test('the outcome comes from prescriptions changed or cancelled after the report', function () {
    ($this->report)(['symptoms' => [($this->symptom)('drowsiness')]]);
    $this->travel(1)->day();

    $this->aviandr->update([...closedAt($this->doctor, now()->toDateTimeString()), 'cancel_reason' => PrescriptionCancelReason::AdverseEvents]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page->where('adverseEvents.0.outcomes', ['Авиандр: отменено 25.10.2026: нежелательные явления']));
});

test('a dose change after the report is the outcome', function () {
    ($this->report)(['symptoms' => [($this->symptom)('drowsiness')]]);
    $this->travel(1)->day();

    $this->aviandr->update(closedAt($this->doctor, now()->toDateTimeString(), PrescriptionCloseReason::Changed));
    Prescription::factory()->for($this->patient, 'patient')->create([
        'previous_id' => $this->aviandr->id,
        'drug_id' => $this->aviandr->drug_id,
        'drug_strength_id' => $this->aviandr->drug_strength_id,
        'starts_on' => '2026-10-25',
    ]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page->where('adverseEvents.0.outcomes', ['Авиандр: назначение изменено 25.10.2026']));
});

test('the doctor records a side effect from the patient’s words without the queue, and the patient sees it', function () {
    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.adverse-events.store', $this->patient), [
            'symptom_id' => ($this->symptom)('dizziness'),
            'severity' => 'mild',
            'started_on' => '2026-10-20',
            'comment' => 'Со слов на приёме',
        ])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    $event = AdverseEvent::query()->sole();

    expect($event->isByDoctor())->toBeTrue()
        ->and($event->reviewed_by_id)->toBe($this->doctor->id)
        ->and($event->level)->toBe(SideEffectLevel::Common)
        ->and(PatientSignals::count($this->doctor))->toBe(0)
        ->and(Alert::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'adverse_event.added')->exists())->toBeTrue();

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.show', $this->patient))
        ->assertInertia(fn (Assert $page) => $page
            ->where('adverseEvents.0.by_doctor', 'Смирнова А. С.')
            ->where('adverseEvents.0.reviewed', null)
            ->where('adverseEvents.0.comment', 'Со слов на приёме'));

    $this->actingAs($this->patient)
        ->get(route('patient.side-effects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reports.0.name', 'Головокружение')
            ->where('reports.0.by_doctor', true));
});

test('the doctor chooses a symptom or writes one, not both', function () {
    $this->actingAs($this->doctor)
        ->post(route('doctor.patients.adverse-events.store', $this->patient), [
            'symptom_id' => ($this->symptom)('dizziness'),
            'other' => 'Шум',
            'severity' => 'mild',
            'started_on' => '2026-10-20',
        ])
        ->assertSessionHasErrors(['symptom_id' => 'Выберите симптом из списка или опишите свой, одно из двух.']);

    expect(AdverseEvent::query()->count())->toBe(0);
});

test('the doctor comments on a reviewed side effect and an empty comment clears it', function () {
    ($this->report)(['symptoms' => [($this->symptom)('drowsiness')]]);
    $event = AdverseEvent::query()->sole();
    $route = route('doctor.patients.adverse-events.comment', ['patient' => $this->patient, 'adverseEvent' => $event]);

    $this->actingAs($this->doctor)->put($route, ['comment' => 'Пройдёт к концу 2-й недели'])->assertRedirect();
    expect($event->refresh()->comment)->toBe('Пройдёт к концу 2-й недели');

    $this->actingAs($this->doctor)->put($route, ['comment' => ''])->assertRedirect();
    expect($event->refresh()->comment)->toBeNull()
        ->and(AuditLog::query()->where('action', 'adverse_event.commented')->count())->toBe(2);
});

test('side effects of one patient are closed to other patients and doctors', function () {
    ($this->report)(['symptoms' => [($this->symptom)('drowsiness')]]);
    $event = AdverseEvent::query()->sole();
    $otherDoctor = User::factory()->doctor()->create();
    $otherPatient = User::factory()->patientOf($otherDoctor)->create();

    $this->actingAs($otherPatient)->post(route('patient.side-effects.resolve', $event))->assertNotFound();
    $this->actingAs($otherPatient)->delete(route('patient.side-effects.unresolve', $event))->assertNotFound();
    $this->actingAs($otherDoctor)->post(route('doctor.patients.adverse-events.review', ['patient' => $this->patient, 'adverseEvent' => $event]))->assertNotFound();
    $this->actingAs($otherDoctor)->post(route('doctor.patients.adverse-events.store', $this->patient), ['other' => 'Шум', 'severity' => 'mild', 'started_on' => '2026-10-20'])->assertNotFound();
    // Запись чужого пациента не найдётся и по адресу своего.
    $this->actingAs($otherDoctor)->post(route('doctor.patients.adverse-events.review', ['patient' => $otherPatient, 'adverseEvent' => $event]))->assertNotFound();
    $this->actingAs($this->patient)->post(route('doctor.patients.adverse-events.review', ['patient' => $this->patient, 'adverseEvent' => $event]))->assertForbidden();
    $this->actingAs($this->doctor)->get(route('patient.side-effects.index'))->assertForbidden();

    expect($event->refresh()->resolved_on)->toBeNull()
        ->and($event->reviewed_at)->toBeNull();
});

test('after skipping a dose for feeling unwell the Today screen suggests telling the doctor until a report', function () {
    adherenceDose($this->aviandr, '2026-10-24', 'skipped', 'morning', [
        'skip_reason' => DoseSkipReason::Unwell,
        'marked_at' => now()->subHour(),
    ]);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('unwellSuggestion', true)
            ->where('canReportSideEffects', true));

    ($this->report)(['symptoms' => [($this->symptom)('drowsiness')]]);

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('unwellSuggestion', false));
});

test('a side effect alert does not count as a help request on the help screen', function () {
    ($this->report)(['symptoms' => [($this->symptom)('trismus')]]);

    $this->actingAs($this->patient)
        ->get(route('patient.help.show'))
        ->assertInertia(fn (Assert $page) => $page->where('lastReport', null));
});
