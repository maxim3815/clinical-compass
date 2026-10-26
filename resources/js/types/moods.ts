/**
 * Настроение после отметки приёма: лицо на время приёма, по желанию.
 */
export type Mood = 'sad' | 'neutral' | 'happy';

// День в карточке врача: лица по времени приёма, пустой день — без лиц.
export type MoodDay = {
    iso: string;
    date: string;
    weekday: string;
    // Полная дата для скринридера: «вторник, 13 октября».
    long: string;
    today: boolean;
    // День входит в идущую серию плохого настроения.
    in_streak: boolean;
    marks: { slot: string; mood: Mood; label: string }[];
};

export type DoctorMoods = {
    days: MoodDay[];
    // Все времена приёма за две недели по порядку: строки сетки.
    slots: string[];
    // Идущая серия от трёх дней: сколько и с какого по какой день.
    streak: { days: number; from: string; to: string } | null;
    // Пациент хоть раз отмечал настроение.
    any: boolean;
    // У пациента есть плановые приёмы: без них лиц не бывает.
    planned: boolean;
};
