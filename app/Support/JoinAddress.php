<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Постоянный адрес врача для подключения на приёме: ссылка, короткая запись для диктовки
 * и QR-код. QR рисуется здесь же, без внешних сервисов.
 */
final readonly class JoinAddress
{
    public function __construct(public string $url) {}

    public static function of(User $doctor): self
    {
        return new self(route('join.show', (string) $doctor->doctorProfile?->join_code));
    }

    /**
     * Адрес без протокола: его читают вслух и набирают на телефоне.
     */
    public function short(): string
    {
        return (string) preg_replace('#^https?://#', '', $this->url);
    }

    /**
     * SVG без XML-заголовка, чтобы вставить его прямо в страницу. Цвет — основной цвет текста.
     */
    public function qrSvg(): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle(256, 2, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(30, 41, 59))),
            new SvgImageBackEnd,
        )))->writeString($this->url);

        return trim(substr($svg, (int) strpos($svg, "\n") + 1));
    }

    /**
     * @return array{url: string, short: string, qr: string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'short' => $this->short(),
            'qr' => $this->qrSvg(),
        ];
    }
}
