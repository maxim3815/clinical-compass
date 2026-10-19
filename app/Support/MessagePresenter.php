<?php

namespace App\Support;

use App\Models\Message;

/**
 * Сообщение чата для страницы и для события в реальном времени: оба получателя видят одно и то же,
 * своё ли сообщение, браузер определяет по sender_id.
 */
final class MessagePresenter
{
    /**
     * @return array{id: int, sender_id: int, body: string, date: string, time: string, read: bool}
     */
    public static function present(Message $message): array
    {
        $sentAt = $message->created_at->setTimezone(DisplayTime::TIMEZONE);

        return [
            'id' => $message->id,
            'sender_id' => $message->sender_id,
            'body' => $message->body,
            // «19 октября 2026»: разделитель дней в ленте.
            'date' => $sentAt->isoFormat('D MMMM YYYY'),
            'time' => $sentAt->format('H:i'),
            'read' => $message->read_at !== null,
        ];
    }
}
