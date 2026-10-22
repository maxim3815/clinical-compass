import type { AdherenceSignal } from './adherence';
import type { AttentionAlert } from './alerts';
import type { Prescription } from './prescriptions';

/**
 * Основной диагноз пациента в списке: по нему врач узнаёт пациента.
 */
export type MainDiagnosis = {
    code: string;
    name: string;
};

/**
 * Строка списка пациентов врача.
 */
export type PatientRow = {
    id: number;
    name: string;
    age: number | null;
    diagnosis: MainDiagnosis | null;
    started_at: string;
    adherence: AdherenceSignal;
    // Открытый алерт: врач ещё не закрыл его решением.
    alert: boolean;
    // Красные находки проверки безопасности без решения врача.
    contraindications: number;
    unread: number;
};

/**
 * Пациент в очереди «Требуют внимания» и причины.
 */
export type AttentionPatient = {
    id: number;
    name: string;
    age: number | null;
    diagnosis: MainDiagnosis | null;
    // Открытые алерты, сначала новые.
    alerts: AttentionAlert[];
    // Препараты с красной находкой без решения.
    contraindications: string[];
    adherence: AdherenceSignal | null;
    // Пропуски, которых врач ещё не видел: пациент в счётчике у «Пациентов».
    new_misses: boolean;
    unread: number;
};

/**
 * Что врачу видно рядом с перепиской: кто пациент, что принимает и что требует внимания.
 */
export type ChatPatientContext = {
    age: number | null;
    sex: 'female' | 'male' | null;
    diagnosis: MainDiagnosis | null;
    prescriptions: Prescription[];
    adherence: AdherenceSignal;
    // Препараты с красной находкой без решения.
    contraindications: string[];
};
