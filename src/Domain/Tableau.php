<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

use InvalidArgumentException;

/**
 * Structure d'un tableau a elimination directe.
 *
 * L'ordre de placement est repris tel quel des feuilles Tab_Final et
 * Tab_Consolation du classeur. Il place la tete de serie 1 face a la
 * seizieme, la 8 face a la 9, et repartit les autres de facon que les
 * mieux classes ne se rencontrent qu'au plus tard. La consolante suit
 * le meme ordre, decale de seize places.
 *
 * Le tableau compte seize positions. Lorsque moins de seize joueurs y
 * figurent, les positions vacantes valent forfait : l'adversaire passe
 * le tour sans jouer.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class Tableau
{
    /** Nombre de positions d'un tableau. */
    public const POSITIONS = 16;

    /**
     * Ordre de placement des tetes de serie, de haut en bas de la
     * feuille. Repris de la colonne A de Tab_Final.
     *
     * @var list<int>
     */
    private const PLACEMENT = [1, 16, 9, 8, 5, 12, 13, 4, 3, 14, 11, 6, 7, 10, 15, 2];

    /** Tours d'un tableau de seize, du premier au dernier. */
    public const TOURS = ['8e', 'quart', 'demie', 'finale'];

    /**
     * Ordre de placement, eventuellement decale.
     *
     * @param int $decalage 0 pour le tableau final, 16 pour la consolante
     * @return list<int>
     */
    public static function placement(int $decalage = 0): array
    {
        return array_map(
            static fn (int $rang): int => $rang + $decalage,
            self::PLACEMENT
        );
    }

    /**
     * Rencontres du premier tour, par couples de positions successives.
     *
     * @param  int $decalage
     * @return list<array{0:int,1:int}>
     */
    public static function premierTour(int $decalage = 0): array
    {
        $ordre  = self::placement($decalage);
        $matchs = [];

        for ($i = 0; $i < count($ordre); $i += 2) {
            $matchs[] = [$ordre[$i], $ordre[$i + 1]];
        }

        return $matchs;
    }

    /**
     * Nombre de rencontres d'un tour.
     *
     * Huitiemes : 8 ; quarts : 4 ; demies : 2 ; finale : 1.
     */
    public static function nombreDeMatchs(string $tour): int
    {
        $index = array_search($tour, self::TOURS, true);

        if ($index === false) {
            throw new InvalidArgumentException("Tour inconnu : $tour.");
        }

        return intdiv(self::POSITIONS, 2 ** ($index + 1));
    }

    /** Tour suivant, ou null pour la finale. */
    public static function tourSuivant(string $tour): ?string
    {
        $index = array_search($tour, self::TOURS, true);

        if ($index === false) {
            throw new InvalidArgumentException("Tour inconnu : $tour.");
        }

        return self::TOURS[$index + 1] ?? null;
    }

    /**
     * Position du vainqueur au tour suivant.
     *
     * Les rencontres etant numerotees de 1 a n dans chaque tour, les
     * matchs 1 et 2 alimentent le match 1 du tour suivant, les matchs
     * 3 et 4 le match 2, et ainsi de suite.
     *
     * @return array{tour:string, match:int, cote:int}|null null si c'est la finale
     */
    public static function destination(string $tour, int $numeroMatch): ?array
    {
        $suivant = self::tourSuivant($tour);

        if ($suivant === null) {
            return null;
        }

        return [
            'tour'  => $suivant,
            'match' => intdiv($numeroMatch + 1, 2),
            'cote'  => $numeroMatch % 2 === 1 ? 1 : 2,
        ];
    }

    /**
     * Libelle lisible d'un tour.
     */
    public static function libelle(string $tour): string
    {
        return match ($tour) {
            '8e'     => 'Huitièmes de finale',
            'quart'  => 'Quarts de finale',
            'demie'  => 'Demi-finales',
            'finale' => 'Finale',
            default  => $tour,
        };
    }

    /**
     * Premier tour reellement dispute, compte tenu de l'effectif.
     *
     * Avec huit joueurs ou moins, les huitiemes n'ont pas lieu d'etre :
     * le tableau demarre en quarts.
     */
    public static function tourDeDepart(int $effectif): string
    {
        if ($effectif > 8) {
            return '8e';
        }

        if ($effectif > 4) {
            return 'quart';
        }

        return $effectif > 2 ? 'demie' : 'finale';
    }
}
