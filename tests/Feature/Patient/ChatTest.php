<?php

use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Events\UnreadMessagesChanged;
use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->care = careBetween($this->doctor, $this->patient);
});

test('patient sees the dialog with the current doctor', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 17:05', 'UTC'));
    $question = Message::factory()->for($this->care)->create(['body' => "Плохо сплю.\nЧто делать?"]);
    $this->travel(1)->minutes();
    $answer = Message::factory()->for($this->care)->fromDoctor()->create(['body' => 'Во сколько принимаете препарат?']);

    $this->actingAs($this->patient)
        ->get(route('patient.chat.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('patient/Chat')
            ->where('chat.id', $this->care->id)
            ->where('chat.doctor', ['name' => 'Смирнова Анна Сергеевна', 'specialty' => 'Психиатр'])
            ->where('chat.has_earlier', false)
            ->where('chat.messages', [
                [
                    'id' => $question->id,
                    'sender_id' => $this->patient->id,
                    'body' => "Плохо сплю.\nЧто делать?",
                    'date' => '19 октября 2026',
                    'time' => '20:05',
                    'read' => false,
                ],
                [
                    'id' => $answer->id,
                    'sender_id' => $this->doctor->id,
                    'body' => 'Во сколько принимаете препарат?',
                    'date' => '19 октября 2026',
                    'time' => '20:06',
                    // Пациент открыл диалог — ответ врача прочитан.
                    'read' => true,
                ],
            ]),
        );
});

test('opening the chat marks doctor messages as read', function () {
    Event::fake([MessagesRead::class, UnreadMessagesChanged::class]);
    $own = Message::factory()->for($this->care)->create();
    $first = Message::factory()->for($this->care)->fromDoctor()->create();
    $last = Message::factory()->for($this->care)->fromDoctor()->create();

    $this->actingAs($this->patient)
        ->get(route('patient.chat.show'))
        ->assertInertia(fn (Assert $page) => $page->where('unreadMessages', 0));

    expect($first->refresh()->read_at)->not->toBeNull()
        ->and($last->refresh()->read_at)->not->toBeNull()
        // Своё сообщение читает врач, а не пациент.
        ->and($own->refresh()->read_at)->toBeNull();

    Event::assertDispatched(MessagesRead::class, fn (MessagesRead $event) => $event->careRelationshipId === $this->care->id
        && $event->readerId === $this->patient->id
        && $event->lastReadId === $last->id);
    Event::assertDispatched(UnreadMessagesChanged::class, fn (UnreadMessagesChanged $event) => $event->user->is($this->patient));
});

test('opening a read chat does not send events', function () {
    Event::fake([MessagesRead::class, UnreadMessagesChanged::class]);
    Message::factory()->for($this->care)->fromDoctor()->read()->create();

    $this->actingAs($this->patient)->get(route('patient.chat.show'))->assertOk();

    Event::assertNotDispatched(MessagesRead::class);
    Event::assertNotDispatched(UnreadMessagesChanged::class);
});

test('patient without a doctor sees no dialog and can not write', function () {
    $this->care->update(['ended_at' => now()]);
    Message::factory()->for($this->care)->create();

    $this->actingAs($this->patient)
        ->get(route('patient.chat.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('patient/Chat')->where('chat', null));

    $this->actingAs($this->patient)->postJson(route('patient.chat.store'), ['body' => 'Здравствуйте'])->assertNotFound();
    $this->actingAs($this->patient)->getJson(route('patient.chat.messages', ['before' => 100]))->assertNotFound();
    $this->actingAs($this->patient)->postJson(route('patient.chat.read'), ['up_to' => 100])->assertNotFound();

    expect(Message::query()->count())->toBe(1);
});

test('after changing the doctor the patient sees only the new dialog', function () {
    Message::factory()->for($this->care)->create(['body' => 'Прежнему врачу']);
    $this->care->update(['ended_at' => now()]);
    $newDoctor = User::factory()->doctor()->create();
    $newCare = CareRelationship::factory()->create(['doctor_id' => $newDoctor->id, 'patient_id' => $this->patient->id]);

    $this->actingAs($this->patient)
        ->get(route('patient.chat.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('chat.id', $newCare->id)
            ->where('chat.messages', []),
        );
});

test('patient sends a message to the doctor', function () {
    Event::fake([MessageSent::class, UnreadMessagesChanged::class]);

    $response = $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => "  Плохо сплю.\n\nЧто делать?  \n"])
        ->assertCreated()
        ->assertJson([
            'sender_id' => $this->patient->id,
            // Пробелы по краям обрезаны, переносы строк внутри остались.
            'body' => "Плохо сплю.\n\nЧто делать?",
            'read' => false,
        ]);

    $message = Message::query()->sole();
    expect($response->json('id'))->toBe($message->id)
        ->and($message->care_relationship_id)->toBe($this->care->id)
        ->and($message->sender_id)->toBe($this->patient->id);

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => $event->message->is($message));
    Event::assertDispatched(UnreadMessagesChanged::class, fn (UnreadMessagesChanged $event) => $event->user->is($this->doctor));
});

test('repeated sending with the same key keeps one message', function () {
    Event::fake([MessageSent::class, UnreadMessagesChanged::class]);
    $key = (string) Str::uuid();

    $first = $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => 'Плохо сплю.', 'key' => $key])
        ->assertCreated();

    // Ответ потерялся, пациент нажал «Отправить» ещё раз.
    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => 'Плохо сплю.', 'key' => $key])
        ->assertOk()
        ->assertJson(['id' => $first->json('id')]);

    expect(Message::query()->count())->toBe(1);
    Event::assertDispatchedTimes(MessageSent::class, 1);
    Event::assertDispatchedTimes(UnreadMessagesChanged::class, 1);

    // Тот же ключ у врача — его собственное сообщение, новый ключ — новое сообщение.
    $this->actingAs($this->doctor)
        ->postJson(route('doctor.patients.chat.store', $this->patient), ['body' => 'Во сколько ложитесь?', 'key' => $key])
        ->assertCreated();
    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => 'Плохо сплю.', 'key' => (string) Str::uuid()])
        ->assertCreated();
    expect(Message::query()->count())->toBe(3);

    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => 'Плохо сплю.', 'key' => 'not-a-key'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('key');
});

test('empty and too long messages are rejected', function (string $body, string $error) {
    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => $body])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => $error]);

    expect(Message::query()->count())->toBe(0);
})->with([
    'empty' => ['', 'Напишите сообщение.'],
    'only spaces' => ["  \n ", 'Напишите сообщение.'],
    'longer than 4000 characters' => [str_repeat('я', 4001), 'Сообщение длиннее 4000 символов. Сократите его или разбейте на несколько.'],
]);

test('message of 4000 cyrillic characters is accepted', function () {
    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => str_repeat('я', 4000)])
        ->assertCreated();

    expect(Str::length(Message::query()->sole()->body))->toBe(4000);
});

test('patient can send 30 messages a minute', function () {
    for ($i = 1; $i <= 30; $i++) {
        $this->actingAs($this->patient)->postJson(route('patient.chat.store'), ['body' => "Сообщение {$i}"])->assertCreated();
    }

    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => 'Ещё одно'])
        ->assertTooManyRequests()
        ->assertJson(['message' => 'Слишком много сообщений подряд. Подождите минуту и отправьте снова.']);

    expect(Message::query()->count())->toBe(30);

    // Лимит у каждого пользователя свой: врач по-прежнему может ответить.
    $this->actingAs($this->doctor)
        ->postJson(route('doctor.patients.chat.store', $this->patient), ['body' => 'Ответ'])
        ->assertCreated();

    $this->travel(61)->seconds();

    $this->actingAs($this->patient)->postJson(route('patient.chat.store'), ['body' => 'Через минуту'])->assertCreated();
});

test('chat shows the last 50 messages and loads earlier ones', function () {
    $messages = Message::factory()->for($this->care)->count(55)->create();

    $this->actingAs($this->patient)
        ->get(route('patient.chat.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('chat.messages', 50)
            ->where('chat.messages.0.id', $messages[5]->id)
            ->where('chat.messages.49.id', $messages[54]->id)
            ->where('chat.has_earlier', true),
        );

    $this->actingAs($this->patient)
        ->getJson(route('patient.chat.messages', ['before' => $messages[5]->id]))
        ->assertOk()
        ->assertJsonCount(5, 'messages')
        ->assertJsonPath('messages.0.id', $messages[0]->id)
        ->assertJsonPath('messages.4.id', $messages[4]->id)
        ->assertJsonPath('has_earlier', false);
});

test('loading earlier messages needs the oldest shown message', function (array $query) {
    $this->actingAs($this->patient)
        ->getJson(route('patient.chat.messages', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('before');
})->with([
    'missing' => [[]],
    'not a number' => [['before' => 'abc']],
]);

test('message received in the open chat is marked read up to it', function () {
    Event::fake([MessagesRead::class, UnreadMessagesChanged::class]);
    $seen = Message::factory()->for($this->care)->fromDoctor()->create();
    // Пришло, пока отправлялась отметка: пациент его ещё не видел.
    $newer = Message::factory()->for($this->care)->fromDoctor()->create();

    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.read'), ['up_to' => $seen->id])
        ->assertNoContent();

    expect($seen->refresh()->read_at)->not->toBeNull()
        ->and($newer->refresh()->read_at)->toBeNull();

    Event::assertDispatched(MessagesRead::class, fn (MessagesRead $event) => $event->lastReadId === $seen->id);
});

test('doctor can not open the patient chat', function () {
    $this->actingAs($this->doctor)->get(route('patient.chat.show'))->assertForbidden();
    $this->actingAs($this->doctor)->postJson(route('patient.chat.store'), ['body' => 'Текст'])->assertForbidden();
});

test('menu counter shows unread doctor messages of the current dialog only', function () {
    Message::factory()->for($this->care)->fromDoctor()->count(2)->create();
    Message::factory()->for($this->care)->fromDoctor()->read()->create();
    Message::factory()->for($this->care)->create();
    // Сообщение прежнего врача из завершённой связи не считается.
    $old = CareRelationship::factory()->ended()->create(['patient_id' => $this->patient->id]);
    Message::factory()->for($old)->fromDoctor()->create();

    $this->actingAs($this->patient)
        ->get(route('patient.home'))
        ->assertInertia(fn (Assert $page) => $page->where('unreadMessages', 2));
});
