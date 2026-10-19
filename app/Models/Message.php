<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение в чате пациента и врача. Диалог — связь врач–пациент. Сообщения не меняются
 * и не удаляются: это часть медицинской переписки.
 *
 * @property int $id
 * @property int $care_relationship_id
 * @property int $sender_id
 * @property string $body
 * @property CarbonImmutable|null $read_at Когда прочитал получатель.
 * @property CarbonImmutable $created_at
 * @property-read CareRelationship $careRelationship
 * @property-read User $sender
 */
#[Fillable(['care_relationship_id', 'sender_id', 'body', 'read_at'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    public const int MAX_LENGTH = 4000;

    // Сколько сообщений показывать за раз: последние при открытии диалога и более ранние по кнопке.
    public const int PAGE_SIZE = 50;

    public const null UPDATED_AT = null;

    /**
     * @return BelongsTo<CareRelationship, $this>
     */
    public function careRelationship(): BelongsTo
    {
        return $this->belongsTo(CareRelationship::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Сообщения собеседника, которые пользователь ещё не прочитал.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unreadBy(Builder $query, User $user): void
    {
        $query->where('sender_id', '<>', $user->id)->whereNull('read_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => 'encrypted',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
