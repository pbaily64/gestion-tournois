<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Generation;

/**
 * La geometrie d'un tableau a elimination : ou va chaque tete de serie.
 *
 * L'ordre de placement se construit par recurrence, et cette recurrence
 * est la definition meme d'un tableau equitable :
 *
 *     ordre(1)  = [1]
 *     ordre(2n) = pour chaque s de ordre(n) : s, puis 2n+1-s
 *
 * Ce qui donne 1-2, puis 1-4-2-3, puis 1-8-4-5-2-7-3-6, etc. La propriete
 * garantie est que les tetes de serie 1 et 2 ne peuvent se rencontrer
 * qu'en finale, 1 a 4 qu'en demie, 1 a 8 qu'en quart. Aucune table de
 * placement figee n'est necessaire : la recurrence vaut pour toutes les
 * tailles.
 *
 * Les EXEMPTS (byes) en decoulent gratuitement. Si le tableau fait 16
 * places pour 11 entrants, les places 12 a 16 sont vides ; comme la
 * recurrence apparie 1 avec 16, 2 avec 15… ce sont mecaniquement les
 * cinq mieux classes qui heritent de l'exemption, ce que prescrit
 * `placement_exempts = mieux_classes`.
 */
final class PlacementTableau
{
    /**
     * Ordre des tetes de serie, position de tableau par position.
     *
     * @return list<int> valeurs 1..$taille
     */
    public static function ordreSeeds(int $taille): array
    {
        $ordre = [1];

        while (count($ordre) < $taille) {
            $suivant = [];
            $somme   = count($ordre) * 2 + 1;

            foreach ($ordre as $seed) {
                $suivant[] = $seed;
                $suivant[] = $somme - $seed;
            }

            $ordre = $suivant;
        }

        return array_slice($ordre, 0, $taille);
    }

    /** La plus petite puissance de 2 superieure ou egale a `$effectif`. */
    public static function taillePuissanceDeDeux(int $effectif): int
    {
        if ($effectif <= 1) {
            return 1;
        }

        $taille = 1;

        while ($taille < $effectif) {
            $taille *= 2;
        }

        return $taille;
    }

    public static function nombreTours(int $taille): int
    {
        return $taille <= 1 ? 0 : (int) log($taille, 2);
    }

    /**
     * Libelle d'un tour, compte a rebours depuis la finale.
     *
     * `$restants` est le nombre d'entites encore en lice au debut du
     * tour. On nomme par le nombre de parties, comme le fait l'usage :
     * 8 entites = quarts de finale.
     */
    public static function libelleTour(int $restants): string
    {
        return match ($restants) {
            2       => 'Finale',
            4       => 'Demi-finales',
            8       => 'Quarts de finale',
            16      => 'Huitiemes de finale',
            32      => 'Seiziemes de finale',
            64      => 'Trente-deuxiemes de finale',
            default => sprintf('Tour a %d', $restants),
        };
    }

    /**
     * La moitie / le quart / la huitieme de tableau d'une position.
     *
     * Sert a `separer_meme_poule` : deux qualifies d'une meme poule ne
     * doivent pas partager la zone demandee.
     *
     * @param string $zone `moitie` | `quart` | `demie`
     */
    public static function zone(int $position, int $taille, string $zone): int
    {
        $parts = match ($zone) {
            'moitie' => 2,
            'quart'  => 4,
            'demie'  => 2,
            default  => 1,
        };

        if ($parts <= 1 || $taille <= 0) {
            return 0;
        }

        return intdiv($position * $parts, max(1, $taille));
    }

    /**
     * Repartit `$nb` entites sur les positions d'un tableau.
     *
     * Rend, pour chaque position 0..taille-1, l'indice (0-base) de
     * l'entite qui l'occupe, ou `null` pour un exempt.
     *
     * @return list<int|null>
     */
    public static function positions(int $nb, int $taille): array
    {
        $positions = [];

        foreach (self::ordreSeeds($taille) as $seed) {
            $positions[] = $seed <= $nb ? $seed - 1 : null;
        }

        return $positions;
    }
}
