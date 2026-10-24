<?php

namespace Database\Seeders\Demo;

use App\Enums\DoseSkipReason;
use App\Enums\PrescriptionCancelReason;
use App\Enums\TimeOfDay;

/**
 * План демо-данных: 15 пациентов из клинических профилей, только то, что умеет этап 1. Номера
 * в комментариях — номера профилей. Маршрутизации пока нет, поэтому у части пациентов назначений
 * нет. Чтобы поправить пациента, достаточно поправить его запись здесь.
 *
 * Клинический профиль у всех: пол и диагнозы. Факты для проверки безопасности внесены у тех,
 * на ком её показывают: у Иванова полный набор в норме, у Григорьева СКФ, у Ковалёвой беременность.
 *
 * Опросники GAD-7 и PHQ-9 — у тех, у кого в профиле есть их баллы: день календаря (неделя
 * профиля × 7) => сумма, между значениями суммы по прямой [медик]. Демо-алертов нет: ответ
 * на 9-й вопрос PHQ-9 везде 0.
 */
final class DemoPatients
{
    /**
     * @return list<DemoPatient>
     */
    public static function all(): array
    {
        return [
            self::ivanov(),
            self::vasilyeva(),
            self::smirnov(),
            self::fedorova(),
            self::kuznetsova(),
            self::grigoryev(),
            self::kovaleva(),
            self::sokolov(),
            self::popova(),
            self::lebedev(),
            self::morozova(),
            self::nikolaev(),
            self::zaytseva(),
            self::belov(),
            self::tarasova(),
        ];
    }

    /**
     * 1.1. Эталонный ответ на «Авиандр», 16-я неделя, ~96%. Под ним входят на показе.
     */
    private static function ivanov(): DemoPatient
    {
        return new DemoPatient('patient', 'Иванов Пётр Алексеевич', '1984-03-12', 'male', [DemoDiagnosis::main('F41.1')], facts: [
            // Полный набор в норме: у «Авиандра» ни находок, ни «Не проверено».
            'gfr' => [95, null],
            'hepatic_impairment' => ['none', null],
            'epilepsy' => [false, null],
            'maoi' => [false, null],
            'qt_prolongation' => [false, null],
            'qt_risk_factors' => [false, null],
            'cardiovascular' => [false, null],
            'diabetes_endocrine' => [false, null],
            'substance_dependence' => [false, null],
        ], courses: [
            DemoCourse::prescribed('maritupirdine', 20, 112, ['morning' => '08:30', 'evening' => '19:30'], fn (int $day, TimeOfDay $time): DemoMark => match (true) {
                $day === 3 && $time === TimeOfDay::Evening => DemoMark::skipped(DoseSkipReason::Unwell, 'Весь день клонило в сон'),
                $time === TimeOfDay::Morning && in_array($day, [11, 48, 85], true) => DemoMark::skipped(DoseSkipReason::Forgot),
                $time === TimeOfDay::Evening && in_array($day, [17, 46, 75, 104], true) => DemoMark::missed(),
                $time === TimeOfDay::Morning && $day % 9 === 5 => DemoMark::late(),
                default => DemoMark::onTime(),
            }),
        ], messages: [
            DemoMessage::patient(110, '21:10', 'Добрый вечер! После таблеток днём клонит в сон. Это нормально?'),
            DemoMessage::doctor(109, '09:40', 'Здравствуйте, Пётр Алексеевич! В первые дни так бывает, обычно это проходит само. Если через неделю сонливость останется, напишите мне.'),
            DemoMessage::patient(82, '20:15', 'Сплю уже до утра, шея почти не болит. Спасибо!'),
            DemoMessage::doctor(82, '20:50', 'Рада слышать! Продолжайте приём в том же режиме, всё обсудим на контрольном визите.'),
        ], scales: [
            // Последний срок (день 112, сегодня) заполнен накануне: карточки на «Сегодня» нет.
            'gad7' => [0 => 14, 14 => 13, 28 => 10, 56 => 7, 112 => 4],
            'phq9' => [0 => 6, 112 => 2],
        ]);
    }

    /**
     * 1.2. Вегетативная тревога у молодой пациентки, эсциталопрам, 8-я неделя, ~92%.
     */
    private static function vasilyeva(): DemoPatient
    {
        return new DemoPatient('patient_1b', 'Васильева Анна Дмитриевна', '1997-09-14', 'female', [DemoDiagnosis::main('F41.1')], courses: [
            DemoCourse::prescribed('escitalopram', 10, 56, ['morning' => '09:00'], fn (int $day): DemoMark => match (true) {
                in_array($day, [9, 23, 38], true) => DemoMark::missed(),
                $day === 30 => DemoMark::skipped(DoseSkipReason::Forgot),
                $day % 6 === 2 => DemoMark::late(120),
                default => DemoMark::onTime(10),
            }),
        ], scales: [
            'gad7' => [0 => 15, 28 => 9, 56 => 5],
            'phq9' => [0 => 5],
        ]);
    }

    /**
     * 1.3. Старшая возрастная группа, эсциталопрам, 16-я неделя, ~94%.
     */
    private static function smirnov(): DemoPatient
    {
        return new DemoPatient('patient_1c', 'Смирнов Алексей Борисович', '1968-06-04', 'male', [DemoDiagnosis::main('F41.1')], courses: [
            DemoCourse::prescribed('escitalopram', 10, 112, ['morning' => '08:00'], fn (int $day): DemoMark => match (true) {
                in_array($day, [14, 33, 51, 70, 92], true) => DemoMark::missed(),
                $day === 60 => DemoMark::skipped(DoseSkipReason::Forgot),
                $day === 81 => DemoMark::skipped(DoseSkipReason::RanOut),
                default => DemoMark::onTime(15),
            }),
        ], scales: [
            'gad7' => [0 => 12, 56 => 7],
            // В профиле PHQ-9 нет: фон настроения «тревожно-подавленный», взяли 7 и 4 [медик].
            'phq9' => [0 => 7, 112 => 4],
        ]);
    }

    /**
     * 1.4. Пароксетин отменён из-за тошноты и диареи, через две недели — «Авиандр», 12-я неделя, ~92%.
     */
    private static function fedorova(): DemoPatient
    {
        return new DemoPatient('patient_1d', 'Фёдорова Мария Сергеевна', '1990-11-18', 'female', [DemoDiagnosis::main('F41.1')], courses: [
            DemoCourse::prescribed('paroxetine', 20, 119, ['morning' => '09:00'], fn (int $day): DemoMark => match ($day) {
                6, 13, 17 => DemoMark::skipped(DoseSkipReason::Unwell, 'Тошнит после таблетки'),
                19 => DemoMark::missed(),
                default => DemoMark::onTime(20),
            })->cancelled(21, PrescriptionCancelReason::AdverseEvents, 'Тошнота и диарея с первых дней приёма, не проходят'),
            DemoCourse::prescribed('maritupirdine', 20, 84, ['morning' => '08:00', 'evening' => '20:00'], fn (int $day, TimeOfDay $time): DemoMark => match (true) {
                $time === TimeOfDay::Morning && in_array($day, [25, 52, 66], true) => DemoMark::skipped(DoseSkipReason::Forgot),
                $time === TimeOfDay::Morning && $day % 21 === 10 => DemoMark::missed(),
                $time === TimeOfDay::Evening && $day === 40 => DemoMark::skipped(DoseSkipReason::RanOut),
                $time === TimeOfDay::Evening && $day % 13 === 7 => DemoMark::missed(),
                default => DemoMark::onTime(),
            }),
        ], scales: [
            // Баллы профиля — по неделям «Авиандра», он начат на 36-й день наблюдения: старт — день 28,
            // 4-я неделя — день 56, 12-я — день 112. Пока шёл пароксетин, тревога не снижалась.
            'gad7' => [0 => 13, 28 => 13, 56 => 8, 112 => 4],
            'phq9' => [0 => 6],
        ]);
    }

    /**
     * 2.1. Переход на «Авиандр», 2-я неделя. Эсциталопрам и гидроксизин назначил невролог,
     * пациентка добавила их сама. Сочетание эсциталопрама с гидроксизином противопоказано (QT):
     * красные находки у обоих ждут решения врача.
     */
    private static function kuznetsova(): DemoPatient
    {
        return new DemoPatient('patient2', 'Кузнецова Елена Викторовна', '1991-07-25', 'female', [
            DemoDiagnosis::main('F41.1'),
            DemoDiagnosis::concomitant('F45.3'),
        ], courses: [
            DemoCourse::prescribed('maritupirdine', 20, 14, ['morning' => '08:00', 'evening' => '20:00'], fn (int $day, TimeOfDay $time): DemoMark => match (true) {
                $day === 2 && $time === TimeOfDay::Evening => DemoMark::late(),
                $day === 4 && $time === TimeOfDay::Evening => DemoMark::missed(),
                $day === 8 && $time === TimeOfDay::Morning => DemoMark::skipped(DoseSkipReason::Forgot),
                default => DemoMark::onTime(),
            }),
            DemoCourse::own('escitalopram', 10, 14, ['morning' => '08:00'], fn (int $day): DemoMark => $day === 10 ? DemoMark::missed() : DemoMark::onTime(), 'невролог Орлова Т. В.'),
            DemoCourse::ownAsNeeded('hydroxyzine', 25, 14, [2 => ['22:10'], 5 => ['15:30'], 9 => ['23:05'], 12 => ['14:20'], 13 => ['21:40']], 'невролог Орлова Т. В.'),
        ], messages: [
            DemoMessage::patient(13, '19:20', 'Здравствуйте! Невролог раньше назначил мне эсциталопрам и атаракс, я добавила их в приложение. Продолжать их вместе с Авиандром?'),
            DemoMessage::doctor(13, '19:45', 'Здравствуйте, Елена Викторовна! Эсциталопрам пока продолжайте. Атаракс принимайте, только если тревога станет очень сильной, и не каждый день. На приёме решим, что оставить.'),
        ], scales: [
            'gad7' => [0 => 16],
            'phq9' => [0 => 8],
        ]);
    }

    /**
     * 2.2. ХБП 3а стадии, СКФ 52: «Авиандр» противопоказан. Назначение вопреки показывают вживую.
     */
    private static function grigoryev(): DemoPatient
    {
        return new DemoPatient('patient_2b', 'Григорьев Роман Николаевич', '1978-01-30', 'male', [
            DemoDiagnosis::main('F41.1'),
            DemoDiagnosis::concomitant('N18.3'),
        ], facts: ['gfr' => [52, null]], scales: [
            'gad7' => [0 => 14],
            'phq9' => [0 => 5],
        ]);
    }

    /**
     * 2.3. Беременность 7 недель: «Авиандр» противопоказан. Назначение вопреки показывают вживую.
     */
    private static function kovaleva(): DemoPatient
    {
        return new DemoPatient('patient_2c', 'Ковалёва Дарья Владимировна', '2000-08-11', 'female', [
            DemoDiagnosis::main('F41.1'),
            DemoDiagnosis::concomitant('Z34.0'),
        ], facts: ['pregnancy' => [true, '7 недель']], scales: [
            'gad7' => [0 => 13],
            'phq9' => [0 => 7],
        ]);
    }

    /**
     * 3.1. Пропускает вечерний приём «Авиандра», 4-я неделя: утром почти всегда, вечером реже половины.
     */
    private static function sokolov(): DemoPatient
    {
        return new DemoPatient('patient3', 'Соколов Михаил Игоревич', '1976-11-02', 'male', [DemoDiagnosis::main('F41.1')], courses: [
            DemoCourse::prescribed('maritupirdine', 20, 28, ['morning' => '08:00', 'evening' => '20:00'], fn (int $day, TimeOfDay $time): DemoMark => match (true) {
                $time === TimeOfDay::Morning => in_array($day, [9, 23], true) ? DemoMark::missed() : DemoMark::onTime(10),
                in_array($day % 7, [1, 3, 5], true) => DemoMark::late(80),
                $day % 7 === 0 => DemoMark::skipped(DoseSkipReason::Forgot, minutes: 210),
                default => DemoMark::missed(),
            }),
        ], messages: [
            DemoMessage::patient(1, '21:40', 'Таблетки не работают, тревога вечером только хуже', read: false),
        ], scales: [
            'gad7' => [0 => 13, 28 => 12],
            'phq9' => [0 => 7],
        ]);
    }

    /**
     * 3.2. Пьёт пароксетин как успокоительное, раз в 2–3 дня после обеда, 3-я неделя.
     */
    private static function popova(): DemoPatient
    {
        return new DemoPatient('patient_3b', 'Попова Екатерина Александровна', '2004-03-27', 'female', [DemoDiagnosis::main('F41.1')], courses: [
            DemoCourse::prescribed('paroxetine', 20, 21, ['morning' => '09:00'], fn (int $day): DemoMark => in_array($day % 5, [1, 3], true) ? DemoMark::late(330) : DemoMark::missed()),
        ], messages: [
            DemoMessage::doctor(12, '10:05', 'Екатерина Александровна, вижу, что вы принимаете пароксетин не каждый день. Он действует, только если принимать его каждое утро во время еды: эффект набирается постепенно. Одна таблетка в тяжёлый момент тревогу не снимет.'),
            DemoMessage::patient(12, '13:20', 'Я думала, его пьют, когда накатывает паника. Поняла, буду стараться каждый день.'),
        ]);
    }

    /**
     * 3.3. Бросил «Авиандр» на 10-й день из-за дневной сонливости и врачу не написал.
     */
    private static function lebedev(): DemoPatient
    {
        return new DemoPatient('patient_3c', 'Лебедев Денис Валентинович', '1985-05-15', 'male', [DemoDiagnosis::main('F41.1')], courses: [
            DemoCourse::prescribed('maritupirdine', 20, 13, ['morning' => '08:00', 'evening' => '20:00'], fn (int $day, TimeOfDay $time): DemoMark => match (true) {
                $day >= 10 => DemoMark::missed(),
                $day === 3 && $time === TimeOfDay::Evening => DemoMark::skipped(DoseSkipReason::Unwell, 'Вялость и сонливость после обеда'),
                $day === 4 && $time === TimeOfDay::Morning => DemoMark::skipped(DoseSkipReason::Unwell, 'Сонливость днём, тяжело работать'),
                default => DemoMark::onTime(),
            }),
        ]);
    }

    /**
     * 4.1. Тяжёлая депрессия: «Авиандр» ей вне показаний. Опросников нет: оба ждут её на «Сегодня»,
     * на показе она заполняет их вживую [медик].
     */
    private static function morozova(): DemoPatient
    {
        return new DemoPatient('patient4', 'Морозова Ольга Павловна', '1988-05-19', 'female', [DemoDiagnosis::main('F32.2')]);
    }

    /**
     * 4.2. Сертралин при депрессии, на 14-й день признаки гипомании. Пациент второго врача. Вчера
     * вечером врач сменил диагноз на F31.0, и сертралин оказался вне показаний [медик].
     */
    private static function nikolaev(): DemoPatient
    {
        return new DemoPatient('patient_4b', 'Николаев Артём Игоревич', '1994-12-09', 'male', [
            DemoDiagnosis::main('F32.1')->resolved(1),
            DemoDiagnosis::main('F31.0', daysAgo: 1),
        ], doctor: 'doctor2', courses: [
            DemoCourse::prescribed('sertraline', 50, 15, ['morning' => '09:00'], fn (int $day): DemoMark => $day === 6 ? DemoMark::late() : DemoMark::onTime(10)),
        ], messages: [
            DemoMessage::patient(13, '20:30', 'Начал принимать, пока без изменений.'),
            DemoMessage::doctor(13, '21:00', 'Артём Игоревич, так и должно быть: эффект обычно появляется через 2–4 недели. Принимайте каждое утро.'),
            DemoMessage::patient(1, '02:40', 'Доктор, я наконец ожил! Сплю по 2 часа и полон сил, запустил сразу четыре проекта.', read: false),
            DemoMessage::patient(1, '02:52', 'Взял пару кредитов под новые идеи, теперь точно всё получится!', read: false),
        ]);
    }

    /**
     * 4.3. Паническое расстройство с агорафобией. В НСИ нет кода F40.01 из профиля, взяли F40.0 [медик].
     * Пациентка терапевта: вчера на приёме ответила на опросники, терапевт заполнил HARS и направил
     * её к Смирновой «желательно» (DemoSeeder).
     */
    private static function zaytseva(): DemoPatient
    {
        return new DemoPatient('patient_4c', 'Зайцева Светлана Юрьевна', '1981-02-22', 'female', [DemoDiagnosis::main('F40.0')], doctor: 'therapist', scales: [
            'gad7' => [0 => 19],
            'phq9' => [0 => 10],
        ]);
    }

    /**
     * 5.1. Переутомление без психиатрического диагноза: любой препарат вне показаний.
     */
    private static function belov(): DemoPatient
    {
        return new DemoPatient('patient_norm1', 'Белов Иван Максимович', '2002-04-17', 'male', [DemoDiagnosis::main('Z73.0')], scales: [
            'gad7' => [0 => 3],
            'phq9' => [0 => 2],
        ]);
    }

    /**
     * 5.2. Ремиссия на 36-й неделе пароксетина, впереди постепенная отмена, ~97%.
     */
    private static function tarasova(): DemoPatient
    {
        return new DemoPatient('patient_norm2', 'Тарасова Надежда Константиновна', '1987-10-08', 'female', [DemoDiagnosis::main('F41.1')], courses: [
            DemoCourse::prescribed('paroxetine', 20, 252, ['morning' => '09:00'], fn (int $day): DemoMark => match (true) {
                $day % 31 === 19 => DemoMark::missed(),
                $day % 11 === 6 => DemoMark::late(),
                default => DemoMark::onTime(15),
            }),
        ], scales: [
            // Стартового балла в профиле нет: «ГТР умеренной степени», взяли 12 из степени 10–14 [медик].
            'gad7' => [0 => 12, 252 => 1],
            'phq9' => [252 => 1],
        ]);
    }
}
