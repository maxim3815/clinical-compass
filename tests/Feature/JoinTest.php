<?php

use App\Actions\IssueAppointmentPin;
use App\Enums\AccessLinkPurpose;
use App\Events\InvitationChanged;
use App\Http\Controllers\JoinController;
use App\Models\AccessLink;
use App\Models\User;
use App\Support\PinAttempts;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->doctor = User::factory()->doctor()->create(['name' => 'Смирнова Анна Сергеевна']);
    $this->doctor->doctorProfile?->update(['join_code' => 'smirnova']);
});

test('doctor address shows who the patient connects to', function (string $code) {
    $this->get(route('join.show', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('join/Show')
            ->where('joinCode', 'smirnova')
            ->where('doctor', ['name' => 'Смирнова Анна Сергеевна', 'specialty' => 'Психиатр'])
            ->where('locked', null),
        );
})->with(['smirnova', 'SMIRNOVA']);

test('unknown doctor address looks like an invalid link', function () {
    $this->get(route('join.show', 'nobody'))
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('access-link/Invalid')->missing('doctor'));

    $this->post(route('join.store', 'nobody'), ['pin' => '1234'])
        ->assertRedirect(route('join.show', 'nobody'));
});

test('signed in user is offered to sign out instead of the pin', function () {
    $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;
    $this->actingAs(User::factory()->patient()->create());

    $this->get(route('join.show', 'smirnova'))
        ->assertInertia(fn (Assert $page) => $page->component('access-link/SignedIn'));

    $this->post(route('join.store', 'smirnova'), ['pin' => $pin])
        ->assertRedirect(route('join.show', 'smirnova'));

    expect(AccessLink::query()->sole()->token_hash)->toBeNull();
});

test('right pin burns and opens the registration form of this doctor', function () {
    Event::fake([InvitationChanged::class]);
    $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;

    $response = $this->post(route('join.store', 'smirnova'), ['pin' => $pin]);

    $invitation = AccessLink::query()->sole();
    $token = basename((string) $response->headers->get('Location'));

    $response->assertRedirect(route('invitation.show', $token));
    expect(strlen($token))->toBe(40)
        ->and($invitation->token_hash)->toBe(AccessLink::hashToken($token))
        ->and($invitation->pin_entered_at)->not->toBeNull()
        ->and($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)))->toBeTrue();
    Event::assertDispatched(InvitationChanged::class, fn (InvitationChanged $event) => $event->invitation->is($invitation));

    $this->get(route('invitation.show', $token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitation/Register')
            ->where('patientName', null)
            ->where('doctor.name', 'Смирнова Анна Сергеевна'),
        );

    $this->post(route('join.store', 'smirnova'), ['pin' => $pin])
        ->assertRedirect(route('join.show', 'smirnova'))
        ->assertSessionHasErrors(['pin' => JoinController::WRONG_PIN]);
});

test('patient registers after the pin and is attached to the doctor', function () {
    Event::fake([InvitationChanged::class]);
    $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;
    $token = basename((string) $this->post(route('join.store', 'smirnova'), ['pin' => $pin])->headers->get('Location'));

    $this->post(route('invitation.store', $token), [
        'name' => 'Кузьмин Олег Викторович',
        'login' => 'kuzmin',
        'password' => 'secret-password',
        'password_confirmation' => 'secret-password',
        'consent' => '1',
    ])->assertRedirect(route('patient.home'));

    $patient = User::query()->where('login', 'kuzmin')->sole();

    expect($patient->currentDoctor?->is($this->doctor))->toBeTrue()
        ->and(AccessLink::query()->sole()->patient_id)->toBe($patient->id);
    Event::assertDispatchedTimes(InvitationChanged::class, 2);
});

test('pin does not work after 15 minutes', function () {
    $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;
    $this->travel(IssueAppointmentPin::LIFETIME_MINUTES)->minutes();
    $this->travel(1)->second();

    $this->post(route('join.store', 'smirnova'), ['pin' => $pin])
        ->assertSessionHasErrors(['pin' => JoinController::WRONG_PIN]);

    expect(AccessLink::query()->sole()->token_hash)->toBeNull();
});

test('new pin replaces the previous one', function () {
    $old = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;
    $new = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;

    if ($old !== $new) {
        $this->post(route('join.store', 'smirnova'), ['pin' => $old])->assertSessionHasErrors('pin');
    }

    $this->post(route('join.store', 'smirnova'), ['pin' => $new])
        ->assertSessionHasNoErrors()
        ->assertRedirectContains('/invite/');
});

test('pin of another doctor does not work at this address', function () {
    $pin = app(IssueAppointmentPin::class)->handle(User::factory()->doctor()->create())->pin;

    $this->post(route('join.store', 'smirnova'), ['pin' => $pin])->assertSessionHasErrors('pin');
});

test('pin is four digits', function (?string $pin) {
    $this->post(route('join.store', 'smirnova'), ['pin' => $pin])
        ->assertSessionHasErrors(['pin' => 'Введите 4 цифры.']);
})->with([null, '123', '12345', '12a4', '１２３４']);

test('ten wrong pins from one address close the input for 15 minutes', function () {
    $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;
    $locked = 'Слишком много неверных попыток. Ввод откроется через 15 минут. Если подключиться нужно сейчас, попросите врача прислать ссылку-приглашение.';

    foreach (range(1, PinAttempts::MAX_FAILURES - 1) as $attempt) {
        $this->post(route('join.store', 'smirnova'), ['pin' => wrongPin($pin)])
            ->assertSessionHasErrors(['pin' => JoinController::WRONG_PIN]);
    }

    $this->post(route('join.store', 'smirnova'), ['pin' => wrongPin($pin)])
        ->assertSessionHasErrors(['pin' => $locked]);

    // Пока ввод закрыт, не проходит и верный PIN.
    $this->travel(5)->minutes();
    $this->post(route('join.store', 'smirnova'), ['pin' => $pin])
        ->assertSessionHasErrors(['pin' => 'Слишком много неверных попыток. Ввод откроется через 10 минут. Если подключиться нужно сейчас, попросите врача прислать ссылку-приглашение.']);
    $this->get(route('join.show', 'smirnova'))
        ->assertInertia(fn (Assert $page) => $page->where('locked', 'Слишком много неверных попыток. Ввод откроется через 10 минут. Если подключиться нужно сейчас, попросите врача прислать ссылку-приглашение.'));

    // С другого адреса ввод открыт.
    $this->withServerVariables(['REMOTE_ADDR' => '93.184.216.34'])
        ->post(route('join.store', 'smirnova'), ['pin' => $pin])
        ->assertRedirectContains('/invite/');
});

test('input opens again 15 minutes after the lock', function () {
    $doctorPin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;

    foreach (range(1, PinAttempts::MAX_FAILURES) as $attempt) {
        $this->post(route('join.store', 'smirnova'), ['pin' => wrongPin($doctorPin)]);
    }

    $this->travel(PinAttempts::LOCK_SECONDS + 1)->seconds();
    $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;

    $this->post(route('join.store', 'smirnova'), ['pin' => $pin])
        ->assertSessionHasNoErrors()
        ->assertRedirectContains('/invite/');
});

test('right pin does not count as a failure', function () {
    foreach (range(1, PinAttempts::MAX_FAILURES - 1) as $attempt) {
        $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;
        $this->post(route('join.store', 'smirnova'), ['pin' => wrongPin($pin)]);
        $this->post(route('join.store', 'smirnova'), ['pin' => $pin])->assertRedirectContains('/invite/');
    }

    $pin = app(IssueAppointmentPin::class)->handle($this->doctor)->pin;
    $this->post(route('join.store', 'smirnova'), ['pin' => $pin])->assertRedirectContains('/invite/');

    expect(AccessLink::query()->where('purpose', AccessLinkPurpose::Invitation)->whereNotNull('token_hash')->count())
        ->toBe(PinAttempts::MAX_FAILURES);
});
