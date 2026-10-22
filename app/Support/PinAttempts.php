<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Неверные попытки ввести PIN с одного IP: после 10 за 15 минут ввод закрыт на 15 минут.
 * Считаются только ошибки, верный PIN счётчик не трогает.
 */
final class PinAttempts
{
    public const int MAX_FAILURES = 10;

    public const int WINDOW_SECONDS = 15 * 60;

    public const int LOCK_SECONDS = 15 * 60;

    public function __construct(private readonly string $ip) {}

    /**
     * Сколько секунд ввод ещё закрыт, 0 — открыт.
     */
    public function lockedFor(): int
    {
        $until = Cache::get($this->lockKey());

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    /**
     * Отмечает ошибку. true — ошибок набралось достаточно и ввод закрыт.
     */
    public function fail(): bool
    {
        RateLimiter::hit($this->failuresKey(), self::WINDOW_SECONDS);

        if (RateLimiter::attempts($this->failuresKey()) < self::MAX_FAILURES) {
            return false;
        }

        // Блокировка отсчитывается от последней ошибки, а не от первой в окне.
        Cache::put($this->lockKey(), now()->getTimestamp() + self::LOCK_SECONDS, self::LOCK_SECONDS);
        RateLimiter::clear($this->failuresKey());

        return true;
    }

    private function failuresKey(): string
    {
        return 'join-pin-failures:'.$this->ip;
    }

    private function lockKey(): string
    {
        return 'join-pin-lock:'.$this->ip;
    }
}
