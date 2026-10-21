<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Рубрика или подрубрика МКБ-10 из справочника НСИ Минздрава.
 *
 * @property string $code
 * @property string $name
 */
#[Table(key: 'code', keyType: 'string', incrementing: false, timestamps: false)]
#[Fillable(['code', 'name'])]
class Icd10Code extends Model
{
    /**
     * Код рубрики «F41» или подрубрики «F41.1».
     */
    public const string CODE_PATTERN = '[A-Z][0-9]{2}(\.[0-9]{1,2})?';

    /**
     * Код из правила покрывает код диагноза: рубрика «F31» — все F31.x, подрубрика «F41.1» — её саму
     * и уточнения F41.1x.
     */
    public static function covers(string $code, string $diagnosis): bool
    {
        return $diagnosis === $code || str_starts_with($diagnosis, str_contains($code, '.') ? $code : $code.'.');
    }

    /**
     * «F41.1 Генерализованное тревожное расстройство».
     */
    public function label(): string
    {
        return $this->code.' '.$this->name;
    }
}
