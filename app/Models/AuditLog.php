<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала клинических действий. Записи только добавляются: изменить и удалить их
 * не даёт триггер в БД.
 *
 * @property int $id
 * @property int $actor_id
 * @property string $action
 * @property int|null $patient_id
 * @property string $subject_type
 * @property int $subject_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property CarbonImmutable|null $created_at
 * @property-read User $actor
 */
#[Table(name: 'audit_log')]
#[Fillable(['actor_id', 'action', 'patient_id', 'subject_type', 'subject_id', 'before', 'after'])]
class AuditLog extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(User $actor, string $action, ?User $patient, Model $subject, ?array $before, ?array $after): self
    {
        return self::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'patient_id' => $patient?->id,
            'subject_type' => $subject->getTable(),
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
