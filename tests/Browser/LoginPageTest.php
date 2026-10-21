<?php

use App\Models\User;

test('login page renders without javascript errors', function () {
    visit(route('login'))
        ->assertSee('Клинический компас')
        ->assertSee('Вход')
        ->assertSee('Введите логин и пароль')
        ->assertNoJavaScriptErrors();
});

test('wrong password shows one error above both fields', function () {
    User::factory()->patient()->create(['login' => 'ivanov']);

    visit(route('login'))
        ->on()->mobile()
        ->fill('login', 'ivanov')
        ->fill('password', 'wrong-password')
        ->press('Войти')
        ->assertSeeIn('[data-test="login-error"]', 'Неверный логин или пароль.')
        ->assertAttribute('#login', 'aria-invalid', 'true')
        ->assertAttribute('#password', 'aria-invalid', 'true')
        ->assertSee('Забыли пароль? Попросите у врача ссылку')
        ->assertNoJavaScriptErrors();
});

// В Tailwind 4 outline-none вместе с focus-visible:outline-2 даёт обводку без стиля:
// фокус с клавиатуры пропадает. Нужен ещё focus-visible:outline-solid.
test('keyboard focus is outlined on fields and buttons', function () {
    $outlined = <<<'JS'
        ((selector) => {
            const element = document.querySelector(selector);
            element.focus({ focusVisible: true });

            return getComputedStyle(element).outlineStyle === 'solid';
        })
        JS;

    visit(route('login'))
        ->assertScript("{$outlined}('#password')")
        ->assertScript("{$outlined}('[data-test=\"login-button\"]')")
        ->assertNoJavaScriptErrors();
});
