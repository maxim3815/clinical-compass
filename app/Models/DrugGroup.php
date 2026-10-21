<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Группа препаратов для правил взаимодействий. Кто в неё входит, записано у препарата (Drug::$groups).
 *
 * @property string $code
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(key: 'code', keyType: 'string', incrementing: false)]
#[Fillable(['code', 'name'])]
class DrugGroup extends Model
{
    public const string CODE_PATTERN = '[a-z][a-z0-9_]*';
}
