/**
 * Поиск препарата по названию, МНН и торговым названиям: в форме пациента и в списке у врача.
 */
export type SearchableDrug = {
    id: number;
    name: string;
    inn: string;
    trade_names: string[];
};

export type DrugOption = {
    key: string;
    drugId: number;
    // Торговое название, если препарат нашёлся по нему, иначе null.
    tradeName: string | null;
    // Что пациент увидит в своих препаратах: торговое название или название препарата.
    label: string;
    // Действующее вещество, если название другое: «трамадол» у «Трамала».
    substance: string | null;
};

/**
 * Регистр, «ё» и знак ® не важны, лишние пробелы тоже.
 */
export function normalizeDrugName(text: string): string {
    return text
        .toLocaleLowerCase('ru')
        .replaceAll('ё', 'е')
        .replace(/[®™]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Запрос для поиска: без дозы и формы, которые пациент переписал с упаковки вместе с названием
 * («Кордарон 200 мг», «Кардиомагнил 75 таб. №30»).
 */
export function drugSearchKey(text: string): string {
    return normalizeDrugName(text)
        .replace(
            /(?:[\s,]+(?:№\s*\d+|\d+(?:[.,]\d+)?\s*(?:мг|мкг|г|мл|%|ме)?|мг|мкг|г|мл|%|ме|таб\.?|табл\.?|таблетк\S*|капс\.?|капсул\S*|шт\.?))+$/u,
            '',
        )
        .trim();
}

/**
 * Насколько название подходит к запросу: 0 — начинается с запроса, 1 — с запроса начинается
 * слово, 2 — запрос начинается с названия («Трамал ретард» — «Трамал»), null — не подходит.
 */
function rank(candidate: string, query: string): number | null {
    const text = normalizeDrugName(candidate);

    if (text.startsWith(query)) {
        return 0;
    }

    if (text.split(/[\s\-(]+/).some((word) => word.startsWith(query))) {
        return 1;
    }

    if (query.startsWith(`${text} `) || query.startsWith(`${text}-`)) {
        return 2;
    }

    return null;
}

/**
 * Подходит ли препарат к запросу по любому из названий: для фильтра списка.
 */
export function drugMatches(
    drug: Omit<SearchableDrug, 'id'>,
    query: string,
): boolean {
    const normalized = normalizeDrugName(query);

    return (
        normalized === '' ||
        [drug.name, drug.inn, ...drug.trade_names].some(
            (name) => rank(name, normalized) !== null,
        )
    );
}

/**
 * Сколько правок (вставка, удаление, замена буквы) отделяет одно слово от другого.
 */
function distance(a: string, b: string): number {
    let previous = Array.from({ length: b.length + 1 }, (_, index) => index);

    for (let i = 1; i <= a.length; i++) {
        const current = [i];

        for (let j = 1; j <= b.length; j++) {
            current[j] = Math.min(
                previous[j] + 1,
                current[j - 1] + 1,
                previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1),
            );
        }

        previous = current;
    }

    return previous[b.length];
}

/**
 * Опечатка в набранном: «Кордорон», «Кардиамагнил». Набранное сравнивается с началом названия
 * той же длины. Короткому запросу прощается одна правка, от 7 букв — две; до 4 букв не
 * сравнивается: слишком много похожих.
 */
function typo(candidate: string, query: string): number | null {
    if (query.length < 4) {
        return null;
    }

    const allowed = query.length >= 7 ? 2 : 1;
    const text = normalizeDrugName(candidate);
    const best = Math.min(
        ...[text, ...text.split(/[\s\-(]+/)].map((word) =>
            distance(query, word.slice(0, query.length)),
        ),
    );

    return best <= allowed ? best : null;
}

/**
 * Варианты по оценке названия: по названию и МНН — сам препарат, по торговому названию — оно.
 * Сначала лучшая оценка, затем по алфавиту.
 */
function collect(
    drugs: SearchableDrug[],
    score: (candidate: string) => number | null,
    limit: number,
): DrugOption[] {
    const found: { score: number; option: DrugOption }[] = [];

    for (const drug of drugs) {
        const scores = [score(drug.name), score(drug.inn)]
            .filter((value): value is number => value !== null)
            .sort((a, b) => a - b);

        if (scores.length > 0) {
            found.push({
                score: scores[0],
                option: {
                    key: String(drug.id),
                    drugId: drug.id,
                    tradeName: null,
                    label: drug.name,
                    substance:
                        normalizeDrugName(drug.name) ===
                        normalizeDrugName(drug.inn)
                            ? null
                            : drug.inn,
                },
            });
        }

        for (const tradeName of drug.trade_names) {
            const tradeScore = score(tradeName);

            if (tradeScore !== null) {
                found.push({
                    score: tradeScore,
                    option: {
                        key: `${drug.id}:${tradeName}`,
                        drugId: drug.id,
                        tradeName,
                        label: tradeName,
                        substance: drug.inn,
                    },
                });
            }
        }
    }

    return found
        .sort(
            (a, b) =>
                a.score - b.score ||
                a.option.label.localeCompare(b.option.label, 'ru'),
        )
        .slice(0, limit)
        .map(({ option }) => option);
}

/**
 * Варианты для запроса: названия, которые начинаются с набранного, затем те, где с него
 * начинается слово, по алфавиту.
 */
export function searchDrugs(
    drugs: SearchableDrug[],
    query: string,
    limit = 8,
): DrugOption[] {
    const key = drugSearchKey(query);

    return key === ''
        ? []
        : collect(drugs, (candidate) => rank(candidate, key), limit);
}

/**
 * Название набрано не до конца: какое-то из названий начинается с набранного или его слово.
 * Тогда «оставить как написано» не предлагается: пациент ещё ищет препарат в списке.
 */
export function isPartOfDrugName(
    drugs: SearchableDrug[],
    query: string,
): boolean {
    const key = drugSearchKey(query);

    return (
        key !== '' &&
        drugs.some((drug) =>
            [drug.name, drug.inn, ...drug.trade_names].some(
                (name) => (rank(name, key) ?? 2) < 2,
            ),
        )
    );
}

/**
 * Похожие названия, если точных вариантов нет: пациент мог ошибиться в букве, а «другой»
 * препарат без проверки сочетаний врач увидит только как текст.
 */
export function similarDrugs(
    drugs: SearchableDrug[],
    query: string,
    limit = 3,
): DrugOption[] {
    const key = drugSearchKey(query);

    return key === ''
        ? []
        : collect(drugs, (candidate) => typo(candidate, key), limit);
}

/**
 * Что выбрал пациент: препарат из списка или «другой» с названием, как он его написал.
 */
export type DrugChoice =
    | ({ kind: 'drug' } & Omit<DrugOption, 'key'>)
    | { kind: 'other'; name: string };
