<?php

namespace App\Support;

/**
 * Baut aus einer einzelnen, vom Administrator wählbaren Akzentfarbe
 * (Administration -> Systemeinstellungen, system_settings.accent_color)
 * die kompletten Tailwind-Farbstufen, die das UI unter dem Namen
 * "laravel" verwendet (50/100/300/500/600/700 + DEFAULT).
 *
 * Der historische Standard ist das Laravel-Rot #FF2D20 – wird kein
 * gültiger Wert hinterlegt, bleibt es dabei.
 */
class AccentPalette
{
    public const DEFAULT = '#FF2D20';

    /**
     * @return array{DEFAULT:string,50:string,100:string,300:string,500:string,600:string,700:string,rgb:string}
     */
    public static function from(?string $hex): array
    {
        $hex = self::normalize($hex) ?? self::DEFAULT;
        [$r, $g, $b] = self::toRgb($hex);

        return [
            'DEFAULT' => $hex,
            '50' => self::mixWithWhite($r, $g, $b, 0.92),
            '100' => self::mixWithWhite($r, $g, $b, 0.84),
            '300' => self::mixWithWhite($r, $g, $b, 0.42),
            '500' => self::mixWithWhite($r, $g, $b, 0.12),
            '600' => $hex,
            '700' => self::darken($r, $g, $b, 0.14),
            'rgb' => "{$r}, {$g}, {$b}",
        ];
    }

    public static function normalize(?string $hex): ?string
    {
        if (! is_string($hex)) {
            return null;
        }

        $hex = trim($hex);

        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $hex, $m)) {
            return '#'.strtoupper($m[1]);
        }

        if (preg_match('/^#?([0-9a-fA-F]{3})$/', $hex, $m)) {
            $c = $m[1];

            return '#'.strtoupper($c[0].$c[0].$c[1].$c[1].$c[2].$c[2]);
        }

        return null;
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private static function toRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function mixWithWhite(int $r, int $g, int $b, float $amount): string
    {
        return self::hex(
            $r + (255 - $r) * $amount,
            $g + (255 - $g) * $amount,
            $b + (255 - $b) * $amount,
        );
    }

    private static function darken(int $r, int $g, int $b, float $amount): string
    {
        return self::hex($r * (1 - $amount), $g * (1 - $amount), $b * (1 - $amount));
    }

    private static function hex(float $r, float $g, float $b): string
    {
        return sprintf(
            '#%02X%02X%02X',
            (int) round(max(0, min(255, $r))),
            (int) round(max(0, min(255, $g))),
            (int) round(max(0, min(255, $b))),
        );
    }
}
