<?php

namespace App\Enums;

enum TeachingMethod: string
{
    case Audio = 'audio';
    case VideoKids = 'video_kids';
    case VideoAdults = 'video_adults';

    public function label(): string
    {
        return match ($this) {
            self::Audio => 'Audio',
            self::VideoKids => 'Video (Kids)',
            self::VideoAdults => 'Video (Adults)',
        };
    }

    /**
     * Hourly rate in pesos for this method.
     */
    public function hourlyRate(): float
    {
        return (float) config("academy.rates.{$this->value}", 0);
    }

    public function isVideo(): bool
    {
        return $this !== self::Audio;
    }

    /**
     * Normalise the messy legacy strings.
     *
     * The old column was a VARCHAR(400) written by several different forms, so
     * it accumulated hyphen/underscore variants ('video-kids', 'video_kids')
     * and a concatenated 'videoadult'. The legacy rate lookup matched these
     * with a chain of substring tests, and anything unrecognised — including
     * the 7 blank rows in production — silently fell through to the audio rate.
     * That fallback is preserved deliberately: changing it would restate
     * historical pay.
     */
    public static function fromLegacy(?string $raw): ?self
    {
        $value = strtolower(trim((string) $raw));

        if ($value === '') {
            return null;
        }

        $value = str_replace('-', '_', $value);

        if (str_contains($value, 'video_kids') || str_contains($value, 'videokids')) {
            return self::VideoKids;
        }

        if (str_contains($value, 'video_adult') || str_contains($value, 'videoadult')) {
            return self::VideoAdults;
        }

        return self::Audio;
    }
}
