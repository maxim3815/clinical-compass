const rules = new Intl.PluralRules('ru-RU');

/**
 * Форма слова для числа: plural(5, ['год', 'года', 'лет']) вернёт «лет».
 */
export function plural(
    count: number,
    [one, few, many]: [string, string, string],
): string {
    switch (rules.select(count)) {
        case 'one':
            return one;
        case 'few':
            return few;
        default:
            return many;
    }
}

export function formatAge(age: number): string {
    return `${age} ${plural(age, ['год', 'года', 'лет'])}`;
}
