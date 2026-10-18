<?php

test('login page renders without javascript errors', function () {
    visit(route('login'))
        ->assertSee('Вход в Клинический компас')
        ->assertNoJavaScriptErrors();
});
