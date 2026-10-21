<?php

namespace Database\Seeders\Demo;

/**
 * Демо-пациент: кто он, у какого врача, какие курсы и какая переписка.
 */
final readonly class DemoPatient
{
    /**
     * @param  list<DemoCourse>  $courses
     * @param  list<DemoMessage>  $messages
     */
    public function __construct(
        public string $login,
        public string $name,
        public string $birthDate,
        public string $doctor = 'doctor',
        public array $courses = [],
        public array $messages = [],
    ) {}
}
