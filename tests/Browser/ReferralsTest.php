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
    // GAD-7 = 21, PHQ-9 = 9: до HADS подсказка «желательно».
    app(SubmitAssessments::class)->handle($this->patient, assessmentAnswers(gad7: 3, phq9: 1, phq9Item9: 0));
});

afterEach(fn () => $this->reverb->stop());

test('the therapist rates HADS and refers, the patient sees the referral and the psychiatrist accepts', function () {
    $therapistPage = visit(route('login'))
        ->fill('login', 'therapist')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->assertSeeIn('[data-test="answered-row"]', 'Орлов Игорь Сергеевич')
        ->assertSeeIn('[data-test="answered-missing"]', 'нет HADS')
        ->click('[data-test="answered-row"] a')
        ->assertPresent('[data-test="referral-suggestion"] > div [data-test="referral-category-desirable"]')
        ->click('[data-test="missing-rate-hads"]')
        ->assertSee('Госпитальная шкала тревоги и депрессии')
        // Варианты словами, баллов на экране нет: отвечать может сам пациент.
        ->assertSeeIn('[data-test="scale-item-0"]', 'Время от времени, иногда')
        ->assertMissing('[data-test="scale-options"]')
        // Неполная форма не уходит, пропуск подсвечен и гаснет сам.
        ->click('[data-test="save-scale"]')
        ->assertSeeIn('[data-test="scale-error"]', 'Нет ответа ещё на 14 утверждений.');

    // Тревога: самые тяжёлые ответы (3), депрессия: «нет» (0).
    foreach (range(0, 13) as $item) {
        $therapistPage->click('[data-test="score-'.$item.'-'.($item % 2 === 0 ? 3 : 0).'"]');
    }

    $therapistPage
        // Признаки отмечает врач: у HADS блок свёрнут, пациент с экраном его не видит. Без них
        // форма не сохраняется, а сама блок не раскрывает: «Сохранить» мог нажать пациент.
        ->assertMissing('[data-test="scale-facts"]')
        ->click('[data-test="save-scale"]')
        ->assertSeeIn('[data-test="scale-error"]', 'Отметьте признаки: их заполняет врач.')
        ->assertMissing('[data-test="scale-facts"]')
        ->assertPathEndsWith('/scales/hads/create')
        ->click('[data-test="open-scale-facts"]')
        ->click('[data-test="fact-mania_signs-no"]')
        ->click('[data-test="fact-psychotic_symptoms-no"]')
        ->assertMissing('[data-test="scale-error"]')
        // Итог и степени — только после сохранения: экран может держать пациент.
        ->assertSeeIn('[data-test="scale-summary"]', 'Отмечено 14 из 14. Суммы — после сохранения.')
        ->assertDontSeeIn('[data-test="scale-save-bar"]', 'выраженная')
        ->click('[data-test="save-scale"]')
        ->assertPathIs('/doctor/patients/'.$this->patient->id)
        ->assertPresent('[data-test="referral-suggestion"] > div [data-test="referral-category-required"]')
        ->assertSeeIn('[data-test="referral-rule-hads_anxiety_severe"]', 'HADS, тревога: 21 из 21')
        ->assertSeeIn('[data-test="wellbeing-hads_anxiety"] [data-test="wellbeing-band"]', 'клинически выраженная тревога')
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
        ->assertSeeIn('[data-test="attention-referral"]', 'HADS: тревога 21')
        ->click('[data-test="attention-row"] a')
        ->assertSeeIn('[data-test="referral"]', 'Решение терапевта совпадает с подсказкой системы.')
        ->click('[data-test="accept-referral"]')
        ->click('[data-test="confirm-accept-referral"]')
        ->assertPathIs('/doctor/patients/'.$this->patient->id)
        ->assertSeeIn('[data-test="accepted-referral"]', 'Кравцова Ирина Олеговна')
        ->assertNoJavaScriptErrors();

});
