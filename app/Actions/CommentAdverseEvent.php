<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\AdverseEvent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Комментарий врача к просмотренному побочному эффекту: что решили. Пустой комментарий убирает
 * прежний. Пишется в журнал аудита.
 */
class CommentAdverseEvent
{
    public function handle(User $doctor, AdverseEvent $event, ?string $comment): void
    {
        if ($doctor->role !== UserRole::Doctor) {
            throw new InvalidArgumentException('Комментарий пишет врач.');
        }

        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        DB::transaction(function () use ($doctor, $event, $comment): void {
            $event = AdverseEvent::query()->with('patient')->lockForUpdate()->findOrFail($event->id);
            $before = $event->comment;

            if ($before === $comment) {
                return;
            }

            $event->update(['comment' => $comment]);

            AuditLog::record($doctor, 'adverse_event.commented', $event->patient, $event, ['comment' => $before], ['comment' => $comment]);
        });
    }
}
