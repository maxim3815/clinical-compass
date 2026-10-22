<?php

use App\Actions\IssueAppointmentPin;
use App\Events\InvitationChanged;
use App\Models\AccessLink;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->doctor->doctorProfile?->update(['join_code' => 'smirnova']);
});

test('doctor gets a four digit pin for 15 minutes with the qr of the own address', function () {
    $response = $this->actingAs($this->doctor)
        ->postJson(route('doctor.invitations.appointment.store'))
        ->assertCreated()
        ->assertJsonPath('expires_in', 900)
        ->assertJsonPath('address.url', route('join.show', 'smirnova'))
        ->assertJsonPath('address.short', str_replace('http://', '', route('join.show', 'smirnova')));

    $pin = $response->json('pin');
    $invitation = AccessLink::query()->sole();

    expect($pin)->toMatch('/^\d{4}$/')
        ->and($response->json('id'))->toBe($invitation->id)
        ->and($response->json('address.qr'))->toStartWith('<svg')
        ->and($invitation->doctor_id)->toBe($this->doctor->id)
        ->and($invitation->token_hash)->toBeNull()
        ->and($invitation->pin_hash)->toBe(AccessLink::hashPin($this->doctor->id, $pin))
        ->and($invitation->pin_hash)->not->toBe(hash('sha256', $pin))
        ->and($invitation->expires_at->between(now()->addMinutes(15)->subMinute(), now()->addMinutes(15)))->toBeTrue();
});

test('new pin revokes the previous one', function () {
    $first = app(IssueAppointmentPin::class)->handle($this->doctor)->link;
    $second = app(IssueAppointmentPin::class)->handle($this->doctor)->link;

    expect($first->refresh()->revoked_at)->not->toBeNull()
        ->and($second->refresh()->revoked_at)->toBeNull()
        ->and(AccessLink::query()->awaitingPin()->sole()->is($second))->toBeTrue();
});

test('pins of different doctors live side by side', function () {
    $other = app(IssueAppointmentPin::class)->handle(User::factory()->doctor()->create())->link;
    app(IssueAppointmentPin::class)->handle($this->doctor);

    expect($other->refresh()->revoked_at)->toBeNull();
});

test('patient can not get a pin or the desk card', function () {
    $this->actingAs(User::factory()->patient()->create());

    $this->postJson(route('doctor.invitations.appointment.store'))->assertForbidden();
    $this->get(route('doctor.invitations.card'))->assertForbidden();
});

test('pin nobody entered is not in the invitation list, entered one is', function () {
    $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;

    $this->actingAs($this->doctor)
        ->get(route('doctor.invitations.index'))
        ->assertInertia(fn (Assert $page) => $page->has('invitations', 0));

    auth()->logout();
    $this->post(route('join.store', 'smirnova'), ['pin' => $pin]);

    $this->actingAs($this->doctor)
        ->get(route('doctor.invitations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.patient_name', null)
            ->where('invitations.0.from_appointment', true)
            ->where('invitations.0.status', 'active')
            ->where('invitations.0.can_revoke', true),
        );
});

test('desk card shows the doctor, the address and the qr', function () {
    $this->actingAs($this->doctor)
        ->get(route('doctor.invitations.card'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/JoinCard')
            ->where('doctor', ['name' => 'Смирнова Анна Сергеевна', 'specialty' => 'Психиатр'])
            ->where('address.url', route('join.show', 'smirnova'))
            ->where('address.short', str_replace('http://', '', route('join.show', 'smirnova')))
            ->where('address.qr', fn (string $qr) => str_starts_with($qr, '<svg')),
        );
});

test('invitation event goes to the doctor channel with the stage', function () {
    $pin = app(IssueAppointmentPin::class)->handle($this->doctor);
    $event = new InvitationChanged($pin->link);

    expect($event->broadcastOn()->name)->toBe('private-App.Models.User.'.$this->doctor->id)
        ->and($event->broadcastAs())->toBe('invitation.changed')
        ->and($event->broadcastWith())->toBe(['id' => $pin->link->id, 'stage' => 'entered', 'patient' => null]);

    $patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Кузьмин Олег Викторович']);
    $pin->link->update(['patient_id' => $patient->id, 'used_at' => now()]);

    expect((new InvitationChanged($pin->link->refresh()))->broadcastWith())->toBe([
        'id' => $pin->link->id,
        'stage' => 'registered',
        'patient' => ['id' => $patient->id, 'name' => 'Кузьмин Олег Викторович'],
    ]);
});

test('invitation event is broadcast through the queue after commit', function () {
    expect(is_subclass_of(InvitationChanged::class, ShouldDispatchAfterCommit::class))->toBeTrue()
        ->and(is_subclass_of(InvitationChanged::class, ShouldBroadcastNow::class))->toBeFalse();
});

test('doctor checks what happened with the pin', function () {
    $this->freezeSecond();
    $issued = app(IssueAppointmentPin::class)->handle($this->doctor);
    $url = route('doctor.invitations.appointment.show', $issued->link);

    $this->actingAs($this->doctor)->getJson($url)
        ->assertOk()
        ->assertJson(['id' => $issued->link->id, 'stage' => 'waiting', 'patient' => null, 'expires_in' => 900]);

    $this->travel(5)->minutes();
    $this->getJson($url)->assertJson(['stage' => 'waiting', 'expires_in' => 600]);

    auth()->logout();
    $this->post(route('join.store', 'smirnova'), ['pin' => $issued->pin]);
    $this->actingAs($this->doctor)->getJson($url)->assertJson(['stage' => 'entered', 'expires_in' => 0]);

    $patient = User::factory()->patientOf($this->doctor)->create(['name' => 'Кузьмин Олег Викторович']);
    $issued->link->update(['patient_id' => $patient->id, 'used_at' => now()]);
    $this->getJson($url)->assertJson([
        'stage' => 'registered',
        'patient' => ['id' => $patient->id, 'name' => 'Кузьмин Олег Викторович'],
    ]);
});

test('pin that expired or was replaced is closed', function () {
    $first = app(IssueAppointmentPin::class)->handle($this->doctor)->link;
    app(IssueAppointmentPin::class)->handle($this->doctor);

    $this->actingAs($this->doctor)
        ->getJson(route('doctor.invitations.appointment.show', $first))
        ->assertJson(['stage' => 'closed', 'expires_in' => 0]);
});

test('doctor can not check a pin of another doctor', function () {
    $other = app(IssueAppointmentPin::class)->handle(User::factory()->doctor()->create())->link;

    $this->actingAs($this->doctor)
        ->getJson(route('doctor.invitations.appointment.show', $other))
        ->assertForbidden();
});
