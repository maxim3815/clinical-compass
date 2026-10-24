<?php

namespace App\Enums;

/**
 * Группа побочного эффекта у препарата по разделу 4 его листка-вкладыша. Срочные группы дают
 * врачу алерт: «сообщить врачу сразу» (urgent) и «нужна помощь сейчас» (emergency), при которой
 * пациенту первой показывается «Позвоните 112».
 */
enum SideEffectLevel: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Urgent = 'urgent';
    case Emergency = 'emergency';

    public function isUrgent(): bool
    {
        return $this === self::Urgent || $this === self::Emergency;
    }

    /**
     * У пациента на нескольких препаратах симптом берёт группу с большим числом: самую срочную,
     * а среди обычных — «часто», а не «реже».
     */
    public function rank(): int
    {
        return match ($this) {
            self::Common => 1,
            self::Uncommon => 0,
            self::Urgent => 2,
            self::Emergency => 3,
        };
    }

    /**
     * Название группы в списке у пациента.
     */
    public function groupLabel(): string
    {
        return match ($this) {
            self::Common => 'Бывает часто',
            self::Uncommon => 'Бывает реже',
            self::Urgent => 'Врач получит срочное уведомление',
            self::Emergency => 'Нужна помощь сейчас: сразу звоните 112',
        };
    }

    public static function strongest(self $a, self $b): self
    {
        return $b->rank() > $a->rank() ? $b : $a;
    }
}
