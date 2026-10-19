<?php

use App\Actions\SendMessage;
use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Events\UnreadMessagesChanged;
use App\Models\CareRelationship;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->doctor = User::factory()->doctor()->create();
    $this->patient = User::factory()->patientOf($this->doctor)->create();
    $this->care = careBetween($this->doctor, $this->patient);
});

test('new message goes to the dialog channel with its text', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 17:05', 'UTC'));
    $message = Message::factory()->for($this->care)->create(['body' => 'Плохо сплю.']);
    $event = new MessageSent($message);

    expect($event->broadcastOn()->name)->toBe('private-chat.'.$this->care->id)
        ->and($event->broadcastAs())->toBe('message.sent')
        ->and($event->broadcastWith())->toBe([
            'id' => $message->id,
            'sender_id' => $this->patient->id,
            'body' => 'Плохо сплю.',
            'date' => '19 октября 2026',
            'time' => '20:05',
            'read' => false,
        ]);
});

test('reading goes to the dialog channel', function () {
    $event = new MessagesRead($this->care->id, $this->doctor->id, 42);

    expect($event->broadcastOn()->name)->toBe('private-chat.'.$this->care->id)
        ->and($event->broadcastAs())->toBe('messages.read')
        ->and($event->broadcastWith())->toBe(['reader_id' => $this->doctor->id, 'last_read_id' => 42]);
});

test('unread counter goes to the user channel and is counted when broadcast', function () {
    $event = new UnreadMessagesChanged($this->doctor);
    Message::factory()->for($this->care)->create();

    expect($event->broadcastOn()->name)->toBe('private-App.Models.User.'.$this->doctor->id)
        ->and($event->broadcastAs())->toBe('unread.changed')
        ->and($event->broadcastWith())->toBe(['count' => 1]);
});

test('chat events are broadcast through the queue after commit', function (string $event) {
    expect(is_subclass_of($event, ShouldDispatchAfterCommit::class))->toBeTrue()
        ->and(is_subclass_of($event, ShouldBroadcastNow::class))->toBeFalse();
})->with([MessageSent::class, MessagesRead::class, UnreadMessagesChanged::class]);

test('queued broadcast keeps the message id, not its text', function () {
    Queue::fake();

    app(SendMessage::class)->handle($this->care, $this->patient, 'Секретный текст');

    Queue::assertPushed(BroadcastEvent::class, 2);
    Queue::assertPushed(BroadcastEvent::class, fn (BroadcastEvent $job) => $job->event instanceof MessageSent
        && ! str_contains(serialize($job), 'Секретный текст'));
});

test('message is not broadcast when the transaction rolls back', function () {
    Queue::fake();

    try {
        DB::transaction(function () {
            app(SendMessage::class)->handle($this->care, $this->patient, 'Текст');

            throw new RuntimeException('Откат');
        });
    } catch (RuntimeException) {
    }

    expect(Message::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('message is saved even when Reverb is unavailable', function () {
    // Порт, на котором никого нет, и настоящая очередь, как в окружении разработки.
    useReverbBroadcaster(port: 1);
    config(['queue.default' => 'database']);

    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => 'Здравствуйте'])
        ->assertCreated();

    expect(Message::query()->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(2);
});

test('message text is encrypted in the database', function () {
    $message = Message::factory()->for($this->care)->create(['body' => 'Плохо сплю.']);

    $stored = DB::table('messages')->where('id', $message->id)->value('body');

    expect($stored)->not->toContain('Плохо сплю')
        ->and(decrypt($stored, false))->toBe('Плохо сплю.')
        ->and($message->refresh()->body)->toBe('Плохо сплю.');
});

/**
 * Запрос Echo на подписку к приватному каналу.
 */
function authorizeChannel(User $user, string $channel): TestResponse
{
    return test()->actingAs($user)->postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-'.$channel,
    ]);
}

describe('channel authorization', function () {
    beforeEach(fn () => useReverbBroadcaster());

    test('participants of the dialog can subscribe', function () {
        authorizeChannel($this->patient, 'chat.'.$this->care->id)->assertOk()->assertJsonStructure(['auth']);
        authorizeChannel($this->doctor, 'chat.'.$this->care->id)->assertOk()->assertJsonStructure(['auth']);
    });

    test('others can not subscribe to the dialog', function () {
        $otherDoctor = User::factory()->doctor()->create();
        $otherPatient = User::factory()->patientOf($otherDoctor)->create();

        authorizeChannel($otherDoctor, 'chat.'.$this->care->id)->assertForbidden();
        authorizeChannel($otherPatient, 'chat.'.$this->care->id)->assertForbidden();
        authorizeChannel($this->patient, 'chat.abc')->assertForbidden();
        authorizeChannel($this->patient, 'chat.999999')->assertForbidden();
    });

    test('nobody can subscribe to the dialog of an ended relationship', function () {
        $this->care->update(['ended_at' => now()]);
        CareRelationship::factory()->create(['patient_id' => $this->patient->id]);

        authorizeChannel($this->patient, 'chat.'.$this->care->id)->assertForbidden();
        authorizeChannel($this->doctor, 'chat.'.$this->care->id)->assertForbidden();
    });

    test('user subscribes only to own counter channel', function () {
        authorizeChannel($this->patient, 'App.Models.User.'.$this->patient->id)->assertOk();
        authorizeChannel($this->patient, 'App.Models.User.'.$this->doctor->id)->assertForbidden();
    });

    test('guest can not subscribe', function () {
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-chat.'.$this->care->id,
        ])->assertForbidden();
    });
});
