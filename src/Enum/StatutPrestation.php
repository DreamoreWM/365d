<?php

namespace App\Enum;

enum StatutPrestation: string
{
    case BROUILLON = 'brouillon';
    case A_PROGRAMMER = 'à programmer';
    case PROGRAMME = 'programmé';
    case EN_COURS = 'en cours';
    case TERMINE = 'terminé';
    case NON_EFFECTUE = 'non effectué';
    case ABSENT = 'absent';

    public function label(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::A_PROGRAMMER => 'À programmer',
            self::PROGRAMME => 'Programmé',
            self::EN_COURS => 'En cours',
            self::TERMINE => 'Terminé',
            self::NON_EFFECTUE => 'Non effectué',
            self::ABSENT => 'Absent',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::BROUILLON => 'brouillon',
            self::A_PROGRAMMER => 'a-programmer',
            self::PROGRAMME => 'programme',
            self::EN_COURS => 'en-cours',
            self::TERMINE => 'termine',
            self::NON_EFFECTUE => 'non-effectue',
            self::ABSENT => 'non-effectue',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::BROUILLON => 'edit_note',
            self::A_PROGRAMMER => 'schedule',
            self::PROGRAMME => 'event',
            self::EN_COURS => 'play_circle',
            self::TERMINE => 'check_circle',
            self::NON_EFFECTUE => 'cancel',
            self::ABSENT => 'person_off',
        };
    }
}
