<?php

use App\Models\User;

test('doctor opens the drug catalog and the aviandr card', function () {
    $this->artisan('app:drugs:import')->assertSuccessful();
    User::factory()->doctor()->create(['login' => 'doctor']);

    visit(route('login'))
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->click('Препараты')
        ->assertPathIs('/doctor/drugs')
        ->assertSee('Эсциталопрам')
        ->assertSee('50 мг, 100 мг')
        ->click('Авиандр')
        ->assertPathIs('/doctor/drugs/maritupirdine')
        ->assertSee('маритупирдин')
        ->assertSee('20 мг, делить нельзя')
        ->assertSee('ЛП-№(002445)-(РГ-RU)')
        ->assertAttributeContains('[data-test="drug-source"] a', 'href', 'https://grls.minzdrav.gov.ru/')
        ->assertNoJavaScriptErrors();
});

test('doctor on a phone sees all strengths without scrolling the table', function () {
    $this->artisan('app:drugs:import')->assertSuccessful();
    User::factory()->doctor()->create(['login' => 'doctor']);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'doctor')
        ->fill('password', 'password')
        ->press('Войти')
        ->assertPathIs('/doctor/patients')
        ->click('[data-sidebar="trigger"]')
        ->click('Препараты')
        ->assertPathIs('/doctor/drugs')
        ->assertSee('50 мг, 100 мг')
        // Таблица прокручивается вбок внутри рамки, поэтому ширины страницы мало.
        ->assertScript('[...document.querySelectorAll("table")].every((table) => table.parentElement.scrollWidth <= table.parentElement.clientWidth)')
        ->assertNoJavaScriptErrors();
});
