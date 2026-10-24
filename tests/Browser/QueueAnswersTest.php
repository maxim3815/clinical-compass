<?php

use App\Models\Assessment;
use App\Models\QueueAnswer;
use App\Models\Referral;
use App\Models\User;

beforeEach(function () {
    $this->reverb = startReverb();
    loadReferralRules();
    loadAlertRules();
    $this->therapist = User::factory()->therapist()->create(['login' => 'therapist', 'name' => 'Кравцова Ирина Олеговна']);
    $this->therapist->doctorProfile?->update(['join_code' => 'kravtsova']);
    User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
});

afterEach(fn () => $this->reverb->stop());

/**
 * Пациент в очереди отвечает на оба опросника: GAD-7 по $gad7 на вопрос, PHQ-9 по 1, 9-й — $item9.
 */
function answerAtDoor(mixed $page, int $gad7 = 3, int $item9 = 0): mixed
{
    $page->click('[data-test="queue-start"]')
        ->assertSee('Часть 1 из 2 · 7 вопросов');

    foreach (range(0, 6) as $item) {
        $page->click("input[name=\"gad7-{$item}\"][value=\"{$gad7}\"]");
    }

    $page->press('Дальше')->assertSee('Часть 2 из 2');

    foreach (range(0, 8) as $item) {
        $page->click("input[name=\"phq9-{$item}\"][value=\"".($item === 8 ? $item9 : 1).'"]');
    }

    return $page->press('Получить код')->assertSee('Ваш код для приёма');
}

test('a patient in the queue answers at the door, the therapist opens the code, rates hars, invites and refers', function () {
    $therapistPage = visit(route('login'))
        ->fill('login', 'therapist')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertPresent('[data-test="patients-empty"]')
        ->assertPresent('[data-test="queue-poster-link"]');

    $patientPage = visit(route('queue.show', 'kravtsova'))
        ->on()->mobile()
        ->assertSee('Вопросы о самочувствии')
        ->assertSee('Приём ведёт: Кравцова Ирина Олеговна, терапевт')
        ->assertSee('ответьте на 16 вопросов')
        ->assertSee('Регистрироваться не нужно')
        ->assertScript(FITS_SCREEN);

    answerAtDoor($patientPage)
        ->assertMissing('[data-test="queue-help"]')
        ->assertSee('Назовите или покажите этот код терапевту на приёме.')
        ->assertDontSee('тревога')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    $code = QueueAnswer::query()->sole()->code;

    expect(trim((string) $patientPage->text('[data-test="queue-code-value"] [aria-hidden="true"]')))->toBe($code);

    // Снова открытый QR на том же телефоне показывает тот же код.
    $patientPage->navigate(route('queue.show', 'kravtsova'))
        ->assertSeeIn('[data-test="queue-code-value"]', $code);

    // Код появился у терапевта без перезагрузки.
    waitForText($therapistPage, "Код {$code}")
        ->assertSeeIn('[data-test="answered-queue-row"]', 'из очереди, без регистрации')
        ->assertSeeIn('[data-test="answered-queue-row"]', 'нет HARS')
        ->click('[data-test="answered-queue-row"] a')
        ->assertPathBeginsWith('/doctor/queue/')
        ->assertSee("Код {$code}")
        ->assertPresent('[data-test="referral-suggestion"] > div [data-test="referral-category-desirable"]')
        ->assertSeeIn('[data-test="queue-filling-gad7"]', 'GAD-7: 21 из 21')
        ->click('[data-test="missing-rate-hars"]')
        ->assertSee('Шкала М. Гамильтона для оценки тревоги')
        ->assertSee("Код {$code} · HARS");

    foreach (range(0, 13) as $item) {
        $therapistPage->click("[data-test=\"score-{$item}-2\"]");
    }

    $therapistPage
        ->click('[data-test="fact-mania_signs-no"]')
        ->click('[data-test="fact-psychotic_symptoms-no"]')
        ->click('[data-test="save-scale"]')
        ->assertPathBeginsWith('/doctor/queue/')
        ->assertPresent('[data-test="referral-suggestion"] > div [data-test="referral-category-required"]')
        ->assertSeeIn('[data-test="queue-filling-hars"]', 'Врач: Кравцова Ирина Олеговна')
        ->press('Зарегистрировать пациента')
        ->assertSeeIn('[data-test="appointment-queue-code"]', "Сверьте {$code} с кодом на телефоне пациента")
        ->assertNoJavaScriptErrors();

    $pin = trim((string) $therapistPage->text('[data-test="appointment-pin"] [aria-hidden="true"]'));

    // Пациент регистрируется на том же телефоне по QR на столе и PIN.
    $patientPage->navigate(route('join.show', 'kravtsova'))
        ->assertSeeIn('[data-test="join-queue-hint"]', "Это новый код, не тот, что вы получили в очереди ({$code}).")
        ->typeSlowly('[aria-label="Цифра 1 из 4"]', $pin, 30)
        ->assertPathBeginsWith('/invite/')
        ->fill('name', 'Зайцева Ольга Петровна')
        ->fill('login', 'zaytseva')
        ->fill('password', 'secret-password')
        ->fill('password_confirmation', 'secret-password')
        ->click('[data-test="consent-text-button"]')
        ->click('[data-test="consent-agree"]')
        ->press('Зарегистрироваться')
        ->assertPathIs('/patient')
        // Ответы из очереди закрыли опросники дня 0: отвечать второй раз не нужно.
        ->assertMissing('[data-test="questionnaire-card"]')
        ->assertNoJavaScriptErrors();

    $patient = User::query()->where('login', 'zaytseva')->sole();

    expect(Assessment::query()->where('patient_id', $patient->id)->count())->toBe(3);

    waitForText($therapistPage, 'Регистрация завершена: Зайцева Ольга Петровна.')
        ->press('Закрыть')
        ->assertSeeIn('[data-test="queue-registered"]', 'Ответы из очереди и HARS — в карточке пациента')
        ->click('[data-test="queue-open-patient"]')
        ->assertPathIs('/doctor/patients/'.$patient->id)
        ->assertPresent('[data-test="referral-suggestion"] > div [data-test="referral-category-required"]')
        ->click('[data-test="refer"]')
        ->click('[data-test="confirm-refer"]')
        ->assertSeeIn('[data-test="referral-open"]', 'Направление ждёт, пока психиатр примет пациента')
        ->assertNoJavaScriptErrors();

    expect(Referral::query()->sole()->category->value)->toBe('required');
});

test('item nine at the door shows help to the patient and a red banner with the code to the therapist', function () {
    $therapistPage = visit(route('login'))
        ->fill('login', 'therapist')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients');

    $patientPage = visit(route('queue.show', 'kravtsova'))
        ->on()->mobile()
        ->assertSee('Регистрироваться не нужно');

    answerAtDoor($patientPage, gad7: 1, item9: 1)
        ->assertSee('Постучите в кабинет и назовите код терапевту, можно без очереди.')
        ->assertSeeIn('[data-test="queue-help"]', 'Если вам сейчас тяжело')
        ->assertPresent('[data-test="call-112"]')
        ->assertPresent('[data-test="call-crisis-line"]')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    $code = QueueAnswer::query()->sole()->code;

    waitForText($therapistPage, "Срочно: код {$code}, пациент в очереди")
        ->assertSeeIn('[data-test="urgent-queue-answer"]', 'Суицидальный риск')
        ->assertSeeIn('[data-test="answered-queue-row"]', 'не открыто')
        ->click('[data-test="urgent-queue-open"]')
        ->assertPathBeginsWith('/doctor/queue/')
        ->assertSeeIn('[data-test="queue-alert"]', 'Пациенту показаны 112, кризисная линия')
        ->assertMissing('[data-test="alert-banner"]')
        ->click('Пациенты')
        ->assertPathIs('/doctor/patients')
        ->assertMissing('[data-test="alert-banner"]')
        ->assertSeeIn('[data-test="answered-queue-row"]', 'Алерт')
        ->assertDontSee('не открыто')
        ->assertNoJavaScriptErrors();
});
