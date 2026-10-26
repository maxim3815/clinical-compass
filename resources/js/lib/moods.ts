import { Frown, Meh, Smile } from '@lucide/vue';
import { plural } from '@/lib/plural';
import type { Mood } from '@/types';

/**
 * Лица настроения: подпись и иконка одни у пациента и у врача.
 */
export const MOOD_FACES: Record<Mood, { label: string; icon: typeof Frown }> = {
    sad: { label: 'Плохое', icon: Frown },
    neutral: { label: 'Обычное', icon: Meh },
    happy: { label: 'Хорошее', icon: Smile },
};

/**
 * «Плохое настроение 3 дня подряд»: метка в списке, в шапке карточки и в разделе. Число
 * не отрывается от слова (неразрывный пробел).
 */
export function lowMoodText(days: number): string {
    return `Плохое настроение ${days} ${plural(days, ['день', 'дня', 'дней'])} подряд`;
}
