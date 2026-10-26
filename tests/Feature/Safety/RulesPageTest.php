<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('rules page lists every active rule with its level and source', function () {
    loadSafetyRules();

    $this->actingAs(User::factory()->doctor()->create())
        ->get(route('doctor.rules.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('doctor/rules/Index')
            ->where('total', 62)
            ->has('drugs', 5)
            ->where('drugs.0.name', 'Авиандр')
            ->has('drugs.0.rules', 14)
            ->where('drugs.0.rules.0.rule', 'maritupirdine.indications')
            ->where('drugs.0.rules.0.codes', ['F41.1', 'F43.2'])
            ->where('drugs.0.rules.0.level_label', 'Противопоказано')
            ->where('drugs.0.rules.0.source.section', '4.1. Показания к применению')
            ->where('drugs.0.rules.4.condition', "fact('gfr') < 60"),
        );
});

test('rules page is for doctors only', function () {
    $this->actingAs(User::factory()->patient()->create())
        ->get(route('doctor.rules.index'))
        ->assertForbidden();
});
