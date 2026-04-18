<?php

namespace App\Enum;

enum CreneauPrestation: string
{
    case MATIN = 'matin';
    case APREM = 'aprem';
    case FIXE  = 'fixe';

    public function label(): string
    {
        return match ($this) {
            self::MATIN => 'Matin',
            self::APREM => 'Après-midi',
            self::FIXE  => 'Heure fixe',
        };
    }

    public function isFlexible(): bool
    {
        return $this !== self::FIXE;
    }
}
