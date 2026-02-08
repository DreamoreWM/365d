<?php

namespace App\Enum;

enum StatutPrestation: string
{
    case A_PROGRAMMER = 'à programmer';
    case PROGRAMME = 'programmé';
    case EN_COURS = 'en cours';
    case TERMINE = 'terminé';
    case NON_EFFECTUE = 'non effectué';

    public function label(): string
    {
        return match ($this) {
            self::A_PROGRAMMER => 'À programmer',
            self::PROGRAMME => 'Programmé',
            self::EN_COURS => 'En cours',
            self::TERMINE => 'Terminé',
            self::NON_EFFECTUE => 'Non effectué',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::A_PROGRAMMER => 'a-programmer',
            self::PROGRAMME => 'programme',
            self::EN_COURS => 'en-cours',
            self::TERMINE => 'termine',
            self::NON_EFFECTUE => 'non-effectue',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::A_PROGRAMMER => 'schedule',
            self::PROGRAMME => 'event',
            self::EN_COURS => 'play_circle',
            self::TERMINE => 'check_circle',
            self::NON_EFFECTUE => 'cancel',
        };
    }
}
