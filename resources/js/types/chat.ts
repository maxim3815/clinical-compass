/**
 * Сообщение чата, как его отдаёт `App\Support\MessagePresenter`: на странице, в ответе
 * на отправку и в событии реального времени.
 */
export type ChatMessage = {
    id: number;
    sender_id: number;
    body: string;
    // «19 октября 2026» по Москве: разделитель дней в ленте.
    date: string;
    time: string;
    read: boolean;
};

/**
 * Диалог на странице чата: последние сообщения по порядку.
 */
export type Chat = {
    id: number;
    messages: ChatMessage[];
    has_earlier: boolean;
};

/**
 * Участник диалога прочитал сообщения собеседника до last_read_id включительно.
 */
export type MessagesRead = {
    reader_id: number;
    last_read_id: number;
};
