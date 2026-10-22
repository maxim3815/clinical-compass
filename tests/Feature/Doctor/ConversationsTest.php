<?php

use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->doctor = User::factory()->doctor()->create();
});

test('doctor without messages sees an empty list', function () {
    User::factory()->patientOf($this->doctor)->create();

    $this->actingAs($this->doctor)
        ->get(route('doctor.conversations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/Conversations')
            ->where('conversations.total', 0),
        );
});

test('dialogs with unread messages come first, then by the last message', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    // Диалога без сообщений в списке нет.
    User::factory()->patientOf($this->doctor)->create(['name' => 'Без сообщений']);
    $read = User::factory()->patientOf($this->doctor)->create(['name' => 'Прочитанный']);
    $older = User::factory()->patientOf($this->doctor)->create(['name' => 'Непрочитанный ранний']);
    $newer = User::factory()->patientOf($this->doctor)->create(['name' => 'Непрочитанный поздний']);

    Message::factory()->for(careBetween($this->doctor, $older))->create();
    $this->travel(1)->minutes();
    Message::factory()->for(careBetween($this->doctor, $newer))->create();
    $this->travel(1)->minutes();
    // Самый свежий диалог, но всё прочитано: после непрочитанных.
    Message::factory()->for(careBetween($this->doctor, $read))->read()->create();
    Message::factory()->for(careBetween($this->doctor, $read))->fromDoctor()->create();

    $this->actingAs($this->doctor)
        ->get(route('doctor.conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.total', 3)
            ->where('conversations.data.0.name', 'Непрочитанный поздний')
            ->where('conversations.data.1.name', 'Непрочитанный ранний')
            ->where('conversations.data.2.name', 'Прочитанный'),
        );
});

test('dialog row shows the start of the last message, its time and unread count', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));
    $patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Иванов Пётр Алексеевич']);
    $care = careBetween($this->doctor, $patient);
    Message::factory()->for($care)->count(2)->create();
    Message::factory()->for($care)->create(['body' => "Плохо сплю.\n\n".str_repeat('Очень длинное сообщение. ', 10)]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.conversations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('conversations.data.0', [
            'patient_id' => $patient->id,
            'name' => 'Иванов Пётр Алексеевич',
            // Переносы строк схлопнуты, текст обрезан до 100 символов.
            'last_message' => 'Плохо сплю. Очень длинное сообщение. Очень длинное сообщение. Очень длинное сообщение. Очень длинное...',
            'last_message_mine' => false,
            'last_message_at' => '12:00',
            'unread_count' => 3,
        ]));
});

test('doctor sees whether the last message is own and the date of an older one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00', 'UTC'));
    $patient = User::factory()->patientOf($this->doctor)->create();
    Message::factory()->for(careBetween($this->doctor, $patient))->fromDoctor()->create(['body' => 'Как самочувствие?']);
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));

    $this->actingAs($this->doctor)
        ->get(route('doctor.conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.data.0.last_message', 'Как самочувствие?')
            ->where('conversations.data.0.last_message_mine', true)
            ->where('conversations.data.0.last_message_at', '17.10.2026')
            ->where('conversations.data.0.unread_count', 0),
        );
});

test('doctor sees only dialogs of current own patients', function () {
    $own = User::factory()->patientOf($this->doctor)->create();
    Message::factory()->for(careBetween($this->doctor, $own))->create();
    $former = CareRelationship::factory()->ended()->create(['doctor_id' => $this->doctor->id]);
    Message::factory()->for($former)->create();
    $foreignDoctor = User::factory()->doctor()->create();
    $foreign = User::factory()->patientOf($foreignDoctor)->create();
    Message::factory()->for(careBetween($foreignDoctor, $foreign))->create();

    $this->actingAs($this->doctor)
        ->get(route('doctor.conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.total', 1)
            ->where('conversations.data.0.patient_id', $own->id),
        );
});

test('read dialogs are ordered by the time of the last message, not by the order of records', function () {
    $later = User::factory()->patientOf($this->doctor)->create(['name' => 'Попова Екатерина Александровна']);
    $earlier = User::factory()->patientOf($this->doctor)->create(['name' => 'Соколов Михаил Игоревич']);
    // Запись Поповой создана раньше, но её последнее сообщение позже.
    Message::factory()->for(careBetween($this->doctor, $later))->fromDoctor()->create(['created_at' => CarbonImmutable::parse('2026-10-20 10:00')]);
    Message::factory()->for(careBetween($this->doctor, $earlier))->fromDoctor()->create(['created_at' => CarbonImmutable::parse('2026-10-09 10:00')]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.conversations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.data.0.name', 'Попова Екатерина Александровна')
            ->where('conversations.data.1.name', 'Соколов Михаил Игоревич'),
        );
});
