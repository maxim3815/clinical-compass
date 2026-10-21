<?php

namespace Database\Seeders\Demo;

/**
 * Сообщение в чате демо-пациента с врачом. Прочитанное собеседник открывает через 15 минут.
 */
final readonly class DemoMessage
{
    private function __construct(
        public int $daysAgo,
        public string $time,
        public bool $fromPatient,
        public string $body,
        public bool $read,
    ) {}

    public static function patient(int $daysAgo, string $time, string $body, bool $read = true): self
    {
        return new self($daysAgo, $time, true, $body, $read);
    }

    public static function doctor(int $daysAgo, string $time, string $body, bool $read = true): self
    {
        return new self($daysAgo, $time, false, $body, $read);
    }
}
