<?php

namespace App\Http\Requests;

use App\Enums\AccessLinkPurpose;
use App\Models\AccessLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Форма, которую открывают по одноразовой ссылке от врача.
 */
abstract class AccessLinkRequest extends FormRequest
{
    abstract protected function purpose(): AccessLinkPurpose;

    /**
     * Имя маршрута страницы ссылки, куда возвращаем при отказе.
     */
    abstract protected function pageRoute(): string;

    /**
     * Форму проверяем только у гостя и только для действующей ссылки, иначе по ошибкам
     * валидации можно было бы перебирать токены. В остальных случаях страница ссылки
     * сама покажет, что делать.
     */
    public function authorize(): bool
    {
        return $this->user() === null
            && AccessLink::findActive($this->purpose(), $this->token()) !== null;
    }

    public function token(): string
    {
        $token = $this->route('token');

        return is_string($token) ? $token : '';
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(to_route($this->pageRoute(), $this->token()));
    }
}
