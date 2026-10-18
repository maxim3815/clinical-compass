<?php

test('login page renders without javascript errors', function () {
    visit(route('login'))
        ->assertSee('Log in to your account')
        ->assertNoJavaScriptErrors();
});
