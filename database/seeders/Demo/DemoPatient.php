<?php

namespace Database\Seeders\Demo;

/**
 * Демо-пациент: кто он, у какого врача, клинический профиль, какие курсы, какая переписка
 * и какие суммы опросников.
 */
final readonly class DemoPatient
{
    /**
     * @param  'female'|'male'  $sex
     * @param  list<DemoDiagnosis>  $diagnoses
     * @param  array<string, array{bool|int|string, string|null}>  $facts  код вида факта => [значение, комментарий];
     *                                                                     врач вносит их при заведении пациента
     * @param  list<DemoCourse>  $courses
     * @param  list<DemoMessage>  $messages
     * @param  array<string, array<int, int>>  $scales  код опросника => [день календаря => сумма] из профиля;
     *                                                  пациент заполняет все опросники на каждом наступившем сроке
     */
    public function __construct(
        public string $login,
        public string $name,
        public string $birthDate,
        public string $sex,
        public array $diagnoses,
        public array $facts = [],
        public string $doctor = 'doctor',
        public array $courses = [],
        public array $messages = [],
        public array $scales = [],
    ) {}
}
