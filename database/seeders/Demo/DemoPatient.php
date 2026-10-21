<?php

namespace Database\Seeders\Demo;

/**
 * Демо-пациент: кто он, у какого врача, клинический профиль, какие курсы и какая переписка.
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
    ) {}
}
