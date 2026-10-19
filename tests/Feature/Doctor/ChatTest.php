<?php

use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Events\UnreadMessagesChanged;
use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Иванов Пётр Алексеевич']);
    $this->care = careBetween($this->doctor, $this->patient);
});

test('doctor opens the dialog with own patient and reads it', function () {
    Event::fake([MessagesRead::class, UnreadMessagesChanged::class]);
    $question = Message::factory()->for($this->care)->create();
    $own = Message::factory()->for($this->care)->fromDoctor()->create();

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.chat.show', $this->patient))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/Chat')
            ->where('patient', ['id' => $this->patient->id, 'name' => 'Иванов Пётр Алексеевич'])
            ->where('chat.id', $this->care->id)
            ->where('chat.messages.0.id', $question->id)
            ->where('chat.messages.0.read', true)
            ->where('chat.messages.1.id', $own->id)
            ->where('chat.messages.1.read', false)
            ->where('unreadMessages', 0),
        );

    expect($own->refresh()->read_at)->toBeNull();
    Event::assertDispatched(MessagesRead::class, fn (MessagesRead $event) => $event->readerId === $this->doctor->id
        && $event->lastReadId === $question->id);
    Event::assertDispatched(UnreadMessagesChanged::class, fn (UnreadMessagesChanged $event) => $event->user->is($this->doctor));
});

test('doctor sends a message to the patient', function () {
    Event::fake([MessageSent::class, UnreadMessagesChanged::class]);

    $this->actingAs($this->doctor)
        ->postJson(route('doctor.patients.chat.store', $this->patient), ['body' => 'Добрый день!'])
        ->assertCreated()
        ->assertJson(['sender_id' => $this->doctor->id, 'body' => 'Добрый день!']);

    $message = Message::query()->sole();
    expect($message->care_relationship_id)->toBe($this->care->id);

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->is($message));
    Event::assertDispatched(UnreadMessagesChanged::class, fn (UnreadMessagesChanged $event) => $event->user->is($this->patient));
});

test('dialog of someone else is not found', function (Closure $patient) {
    $other = $patient($this->doctor);

    $this->actingAs($this->doctor)->get(route('doctor.patients.chat.show', $other))->assertNotFound();
    $this->actingAs($this->doctor)->getJson(route('doctor.patients.chat.messages', [$other, 'before' => 100]))->assertNotFound();
    $this->actingAs($this->doctor)->postJson(route('doctor.patients.chat.store', $other), ['body' => 'Текст'])->assertNotFound();
    $this->actingAs($this->doctor)->postJson(route('doctor.patients.chat.read', $other), ['up_to' => 100])->assertNotFound();

    expect(Message::query()->count())->toBe(0);
})->with([
    'patient of another doctor' => fn (User $doctor) => User::factory()->patientOf(User::factory()->doctor()->create())->create(),
    'former patient' => function (User $doctor) {
        $patient = User::factory()->patient()->create();
        CareRelationship::factory()->ended()->create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id]);

        return $patient;
    },
    'another doctor' => fn (User $doctor) => User::factory()->doctor()->create(),
]);

test('non-numeric patient id is not found', function () {
    $this->actingAs($this->doctor)->get('/doctor/patients/abc/chat')->assertNotFound();
});

test('patient can not open the doctor chat pages', function () {
    $this->actingAs($this->patient)->get(route('doctor.conversations.index'))->assertForbidden();
    $this->actingAs($this->patient)->get(route('doctor.patients.chat.show', $this->patient))->assertForbidden();
});

test('menu counter shows dialogs with unread patient messages', function () {
    Message::factory()->for($this->care)->count(3)->create();
    $second = User::factory()->patientOf($this->doctor)->create();
    Message::factory()->for(careBetween($this->doctor, $second))->create();
    // Прочитанный диалог, свои сообщения и завершённая связь не считаются.
    $third = User::factory()->patientOf($this->doctor)->create();
    Message::factory()->for(careBetween($this->doctor, $third))->read()->create();
    Message::factory()->for(careBetween($this->doctor, $third))->fromDoctor()->create();
    $former = CareRelationship::factory()->ended()->create(['doctor_id' => $this->doctor->id]);
    Message::factory()->for($former)->create();

    $this->actingAs($this->doctor)
        ->get(route('doctor.patients.index'))
        ->assertInertia(fn (Assert $page) => $page->where('unreadMessages', 2));
});
