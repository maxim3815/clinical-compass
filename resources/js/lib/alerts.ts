/**
 * Алерт у врача одной строкой, одинаково в плашке, очереди и карточке:
 * «Суицидальный риск — PHQ-9, вопрос 9: «почти каждый день»».
 */
export function alertTitle(rule: string, trigger: string): string {
    return `${rule} — ${trigger}`;
}
