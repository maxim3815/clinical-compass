/**
 * Постоянный адрес врача для подключения на приёме.
 */
export type JoinAddress = {
    url: string;
    // Без протокола, чтобы продиктовать: compas.example.ru/join/abc234.
    short: string;
    // SVG QR-кода без XML-заголовка.
    qr: string;
};

/**
 * Только что выданный PIN для окна «Пригласить на приёме».
 */
export type AppointmentPin = {
    id: number;
    pin: string;
    // Сколько секунд PIN действует с момента ответа.
    expires_in: number;
    address: JoinAddress;
};

/**
 * Событие invitation.changed: пациент ввёл PIN или зарегистрировался по приглашению.
 */
export type InvitationChanged = {
    id: number;
    stage: 'entered' | 'registered';
    patient: { id: number; name: string } | null;
};

/**
 * Врач на гостевых страницах: кто приглашает пациента.
 */
export type InvitingDoctor = {
    name: string;
    specialty: string | null;
};
