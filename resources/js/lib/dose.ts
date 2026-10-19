import { plural } from '@/lib/plural';

const mgFormat = new Intl.NumberFormat('ru-RU', {
    maximumFractionDigits: 2,
    useGrouping: false,
});

/**
 * «½ таблетки», «1 таблетка», «1½ таблетки», «5 таблеток». Как `App\Support\Dose::tablets()`.
 */
export function formatTablets(tablets: number): string {
    const halves = Math.round(tablets * 2);
    const whole = Math.floor(halves / 2);

    if (halves % 2 === 1) {
        return `${whole > 0 ? whole : ''}½ таблетки`;
    }

    return `${whole} ${plural(whole, ['таблетка', 'таблетки', 'таблеток'])}`;
}

/**
 * «20 мг», «12,5 мг».
 */
export function formatMg(mg: number): string {
    return `${mgFormat.format(mg)} мг`;
}
