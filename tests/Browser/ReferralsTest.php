<?php

use App\Actions\SubmitAssessments;
use App\Models\Referral;
use App\Models\User;

beforeEach(function () {
    $this->reverb = startReverb();
    loadReferralRules();
    $this->therapist = User::factory()->therapist()->create(['login' => 'therapist', 'name' => 'Кравцова Ирина Олеговна']);
    User::factory()->doctor()->create(['login' => 'doctor', 'name' => 'Смирнова Анна Сергеевна']);
    $this->patient = User::factory()->patientOf($this->therapist)->create(['login' => 'orlov', 'name' => 'Орлов Игорь Сергеевич']);
    // GAD-7 = 21, PHQ-9 = 9: до HARS подсказка «желательно».
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: 3, phq9: 1, phq9Item9: 0));
});

afterEach(fn () => $this->reverb->stop());

test('the therapist rates HARS and refers, the patient sees the referral and the psychiatrist accepts', function () {
    $therapistPage = visit(route('login'))
        ->fill('login', 'therapist')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertSeeIn('[data-test="answered-row"]', 'Орлов Игорь Сергеевич')
        ->assertSeeIn('[data-test="answered-missing"]', 'нет HARS')
        ->click('[data-test="answered-row"] a')
        ->assertPresent('[data-test="referral-suggestion"] > div [data-test="referral-category-desirable"]')
        ->click('[data-test="missing-rate-hars"]')
        ->assertSee('Шкала М. Гамильтона для оценки тревоги')
        // Неполная форма не уходит, пропуск подсвечен и гаснет сам.
        ->click('[data-test="save-scale"]')
        ->assertSeeIn('[data-test="scale-error"]', 'Оцените ещё 14 пунктов.');

    foreach (range(0, 13) as $item) {
        $therapistPage->click("[data-test=\"score-{$item}-2\"]");
    }

    $therapistPage
        ->click('[data-test="fact-mania_signs-no"]')
        ->click('[data-test="fact-psychotic_symptoms-no"]')
        ->assertMissing('[data-test="scale-error"]')
        ->assertSeeIn('[data-test="scale-summary"]', 'Сумма 28 из 56')
        ->click('[data-test="save-scale"]')
        ->assertPathIs('/doctor/patients/'.$this->patient->id)
        ->assertPresent('[data-test="referral-suggestion"] > div [data-test="referral-category-required"]')
        ->assertSeeIn('[data-test="referral-rule-hars_severe"]', 'HARS: 28 из 56')
        ->click('[data-test="refer"]')
        ->assertSeeIn('[data-test="refer-dialog"]', 'подсказка системы')
        ->click('[data-test="confirm-refer"]')
        ->assertSeeIn('[data-test="referral-open"]', 'Направление ждёт, пока психиатр примет пациента')
        ->assertNoJavaScriptErrors();

    expect(Referral::query()->sole()->category->value)->toBe('required');

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'orlov')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertSeeIn('[data-test="referral-card"]', 'Смирнова Анна Сергеевна')
        ->assertSeeIn('[data-test="referral-card-category"]', 'Терапевт считает, что вам важно получить консультацию психиатра')
        ->assertMissing('[data-test="tab-chat"]')
        ->assertMissing('[data-test="report-side-effect-link"]')
        ->assertScript(FITS_SCREEN)
        ->assertNoJavaScriptErrors();

    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertPresent('[data-test="attention-referral"] [data-test="referral-category-required"]')
        ->assertSeeIn('[data-test="attention-referral"]', 'HARS 28')
        ->click('[data-test="attention-row"] a')
        ->assertSeeIn('[data-test="referral"]', 'Решение терапевта совпадает с подсказкой системы.')
        ->click('[data-test="accept-referral"]')
        ->click('[data-test="confirm-accept-referral"]')
        ->assertPathIs('/doctor/patients/'.$this->patient->id)
        ->assertSeeIn('[data-test="accepted-referral"]', 'Кравцова Ирина Олеговна')
        ->assertNoJavaScriptErrors();

});
