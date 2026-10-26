import type { MainDiagnosis } from './patients';

/**
 * Направление к психиатру от терапевта: подсказка системы, решение терапевта, принятие психиатром.
 */

export type ReferralCategory = 'required' | 'desirable';

// На что смотрело сработавшее правило подсказки.
export type ReferralEvidence =
    | {
          type: 'answer';
          scale: string;
          name: string;
          item: number;
          text: string;
          score: number;
          label: string;
      }
    | {
          type: 'total';
          scale: string;
          name: string;
          total: number;
          max: number;
          band: string;
      }
    | { type: 'fact'; code: string; name: string; value: string };

export type ReferralMatch = {
    code: string;
    category: ReferralCategory;
    text: string;
    evidence: ReferralEvidence[];
    source: { document: string; section: string; url: string; quote?: string };
};

export type ReferralSuggestion = {
    category: ReferralCategory | null;
    // Сначала «обязательно», внутри — по порядку правил.
    matches: ReferralMatch[];
    // Шкалы, без которых часть правил не проверена.
    missing: { scale: string; name: string; rater: 'patient' | 'doctor' }[];
};

export type ReferralStatus = 'open' | 'accepted' | 'withdrawn';

export type ReferralDoctor = { name: string; specialty: string | null };

export type Referral = {
    id: number;
    category: ReferralCategory;
    suggested_category: ReferralCategory | null;
    basis: ReferralMatch[];
    comment: string | null;
    from: ReferralDoctor;
    to: ReferralDoctor;
    status: ReferralStatus;
    created_at: string;
    accepted_at: string | null;
};

// Последнее решение терапевта «не направлять».
export type ReferralDeclined = {
    at: string;
    comment: string | null;
    suggested_category: ReferralCategory | null;
};

// Строка очереди психиатра: направление, которое его ждёт.
export type IncomingReferral = {
    id: number;
    category: ReferralCategory;
    from: ReferralDoctor;
    created_at: string;
    // Основание одной строкой: «HADS, тревога 12 · GAD-7 16».
    summary: string;
};

// Строка «Направлены» у терапевта.
export type SentReferral = {
    id: number;
    patient: { id: number; name: string; age: number | null };
    category: ReferralCategory;
    to: string;
    status: ReferralStatus;
    created_at: string;
    accepted_at: string | null;
};

// Строка «Ответили сегодня» у терапевта.
type AnsweredCommon = {
    id: number;
    at: string;
    scales: { name: string; total: number; max: number; band: string }[];
    // Ответ на 9-й вопрос PHQ-9 не «совсем нет».
    highlight: boolean;
    alert: boolean;
    suggestion: ReferralCategory | null;
    // Шкалы, без которых подсказка может быть ниже (у терапевта — HADS).
    scales_missing: string[];
    // Решение терапевта после этих ответов.
    decision: {
        kind: 'referred' | 'declined';
        category: ReferralCategory | null;
    } | null;
};

/**
 * Строка «Ответили сегодня»: пациент терапевта или ответы из очереди у двери с кодом.
 */
export type AnsweredToday =
    | (AnsweredCommon & {
          kind: 'patient';
          name: string;
          age: number | null;
      })
    | (AnsweredCommon & {
          kind: 'queue';
          code: string;
          // Сигнал по 9-му вопросу, а терапевт ответы ещё не открывал.
          unopened: boolean;
          // Когда терапевт открыл ответы, ЧЧ:ММ; null — ещё не открывал.
          opened_at: string | null;
      });

// Строка всех пациентов терапевта.
export type TherapistPatientRow = {
    id: number;
    name: string;
    age: number | null;
    diagnosis: MainDiagnosis | null;
    started_at: string;
    alert: boolean;
    referred: boolean;
};

// Карточка на «Сегодня» у пациента.
export type PatientReferral = {
    to: ReferralDoctor;
    // Мягкими словами: «Терапевт рекомендует консультацию психиатра».
    category: string;
    status: ReferralStatus;
    accepted_at: string | null;
};
