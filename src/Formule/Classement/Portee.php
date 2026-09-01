<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

/**
 * Portee d'application d'un critere de departage.
 *
 * ToutePoule    — le critere se calcule sur toutes les parties de la
 *                 poule, y compris celles disputees contre des joueurs
 *                 deja departages.
 *
 * EntreExAequo  — le critere ne retient que les parties disputees entre
 *                 les entites encore a egalite. C'est le
 *                 « sous-championnat » des reglements FFTT et FRBTT ;
 *                 a deux, il se ramene a la confrontation directe.
 *
 * Le choix entre les deux change le resultat, pas seulement le chemin :
 * c'est l'un des points les plus sous-estimes de la section 7.
 */
enum Portee: string
{
    case ToutePoule   = 'toute_la_poule';
    case EntreExAequo = 'entre_ex_aequo';

    public function libelle(): string
    {
        return match ($this) {
            self::ToutePoule   => 'toute la poule',
            self::EntreExAequo => 'entre ex æquo',
        };
    }
}
