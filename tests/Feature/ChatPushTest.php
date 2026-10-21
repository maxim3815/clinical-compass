<?php

use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessage;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->freezeSecond();
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Соколов Игорь Петрович']);
    $this->care = careBetween($this->doctor, $this->patient);
});

test('the doctor with push on gets a delayed push about a patient message', function () {
    subscribeToPush($this->doctor);

    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => 'Стало хуже спать'])
        ->assertCreated();

    Notification::assertSentTo($this->doctor, NewMessage::class, function (NewMessage $notification): bool {
        $message = $notification->toWebPush($this->doctor)->toArray();

        return $notification->delay?->eq(now()->addSeconds(NewMessage::DELAY_SECONDS))
            && $message['title'] === 'Соколов Игорь Петрович'
            && $message['body'] === 'Новое сообщение'
            && $message['tag'] === 'chat-'.$this->care->id
            && $message['data']['url'] === '/doctor/patients/'.$this->patient->id.'/chat';
    });
    Notification::assertNotSentTo($this->patient, NewMessage::class);
});

test('the patient gets the doctor name with initials and no message text', function () {
    subscribeToPush($this->patient);

    $this->actingAs($this->doctor)
        ->postJson(route('doctor.patients.chat.store', $this->patient), ['body' => 'Если стало хуже, напишите, обсудим дозу'])
        ->assertCreated();

    Notification::assertSentTo($this->patient, NewMessage::class, function (NewMessage $notification): bool {
        $message = $notification->toWebPush($this->patient)->toArray();

        return $message['title'] === 'Врач Смирнова А. С.'
            && $message['body'] === 'Новое сообщение'
            && $message['data']['url'] === '/patient/chat';
    });
});

test('nobody is notified without push', function () {
    $this->actingAs($this->patient)
        ->postJson(route('patient.chat.store'), ['body' => 'Здравствуйте'])
        ->assertCreated();

    Notification::assertNothingSent();
});

test('the push is dropped when the message was read in the open chat or the care ended', function () {
    $message = Message::factory()->for($this->care)->create();
    $notification = new NewMessage($message);

    expect($notification->shouldSend($this->doctor, 'webpush'))->toBeTrue();

    $message->update(['read_at' => now()]);
    expect($notification->shouldSend($this->doctor, 'webpush'))->toBeFalse();

    $message->update(['read_at' => null]);
    $this->care->update(['ended_at' => now()]);
    expect($notification->shouldSend($this->doctor, 'webpush'))->toBeFalse();
});
