<?php

namespace App\Actions;

use App\Events\MessagesRead;
use App\Events\UnreadMessagesChanged;
use App\Models\CareRelationship;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Участник открыл диалог или получил сообщение в открытом диалоге: сообщения собеседника
 * прочитаны. Сообщения новее upToId остаются непрочитанными: их читатель ещё не видел.
 */
class MarkMessagesRead
{
    /**
     * @return int сколько сообщений стали прочитанными
     */
    public function handle(CareRelationship $careRelationship, User $reader, ?int $upToId = null): int
    {
        $unread = $careRelationship->messages()->unreadBy($reader);

        if ($upToId !== null) {
            $unread->where('id', '<=', $upToId);
        }

        // Граница фиксируется заранее: сообщение, пришедшее во время обновления, не прочитано.
        $lastId = (clone $unread)->max('id');

        if ($lastId === null) {
            return 0;
        }

        $count = $unread->where('id', '<=', $lastId)->update(['read_at' => CarbonImmutable::now()]);

        // Те же сообщения уже отметил параллельный запрос, например из другой вкладки.
        if ($count === 0) {
            return 0;
        }

        MessagesRead::dispatch($careRelationship->id, $reader->id, (int) $lastId);
        UnreadMessagesChanged::dispatch($reader);

        return $count;
    }
}
