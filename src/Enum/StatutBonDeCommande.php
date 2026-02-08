<?php

namespace App\Enum;

enum StatutBonDeCommande: string
{
    case A_PROGRAMMER = 'à programmer';
    case PROGRAMME = 'programmé';
    case EN_COURS = 'en cours';
    case TERMINE = 'terminé';

    public function label(): string
    {
        return match ($this) {
            self::A_PROGRAMMER => 'À programmer',
            self::PROGRAMME => 'Programmé',
            self::EN_COURS => 'En cours',
            self::TERMINE => 'Terminé',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::A_PROGRAMMER => 'a-programmer',
            self::PROGRAMME => 'programme',
            self::EN_COURS => 'en-cours',
            self::TERMINE => 'termine',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::A_PROGRAMMER => 'schedule',
            self::PROGRAMME => 'event',
            self::EN_COURS => 'play_circle',
            self::TERMINE => 'check_circle',
        };
    }
}
