<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

use InvalidArgumentException;

/**
 * Ordre de lancement des matchs d'une poule, et designation de l'arbitre.
 *
 * Sequences reprises de la feuille ORDRE MATCH du classeur, elles-memes
 * conformes aux feuilles de poule diffusees par les federations de
 * tennis de table. Elles ne sont PAS recalculees : la convention est
 * partagee par les clubs, les joueurs la reconnaissent, et un litige se
 * tranche en se referant au document officiel.
 *
 * Proprietes de ces sequences :
 *
 *  - Chaque paire se rencontre une fois et une seule.
 *  - Des 5 joueurs, personne n'enchaine deux matchs de suite. En poule
 *    de 3 et de 4, c'est mathematiquement impossible : la borne est de
 *    deux enchainements, et les sequences l'atteignent.
 *  - L'arbitre ne dispute jamais le match qu'il arbitre.
 *
 * UNE CORRECTION a ete apportee : en poule de 8, le match 16 (B contre
 * H) designait B comme arbitre, ce qui est impossible. L'arbitre est F,
 * libre a ce moment-la. La cellule AI20 de la feuille ORDRE MATCH du
 * classeur doit etre corrigee de la meme facon.
 *
 * LIMITE CONNUE : la charge d'arbitrage n'est pas equilibree en poule de
 * 8, ou un joueur arbitre cinq fois quand un autre ne le fait que deux.
 * C'est un defaut de la convention, conserve par fidelite.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class OrdreMatchs
{
    /**
     * Pour chaque taille de poule : la liste ordonnee des rencontres,
     * sous la forme [joueur 1, joueur 2, arbitre].
     *
     * @var array<int, list<array{0:string,1:string,2:string}>>
     */
    private const SEQUENCES = [
        3 => [
            ['A', 'C', 'B'], ['B', 'C', 'A'], ['A', 'B', 'C'],
        ],
        4 => [
            ['A', 'D', 'B'], ['B', 'C', 'A'], ['A', 'C', 'D'],
            ['B', 'D', 'C'], ['C', 'D', 'B'], ['A', 'B', 'D'],
        ],
        5 => [
            ['A', 'E', 'B'], ['B', 'D', 'C'], ['C', 'E', 'A'],
            ['A', 'D', 'E'], ['B', 'C', 'D'], ['D', 'E', 'A'],
            ['A', 'C', 'E'], ['B', 'E', 'D'], ['C', 'D', 'B'],
            ['A', 'B', 'C'],
        ],
        6 => [
            ['A', 'F', 'D'], ['B', 'D', 'E'], ['C', 'E', 'F'],
            ['D', 'F', 'C'], ['B', 'C', 'A'], ['A', 'E', 'B'],
            ['C', 'F', 'D'], ['B', 'E', 'F'], ['A', 'D', 'E'],
            ['B', 'F', 'C'], ['D', 'E', 'A'], ['A', 'C', 'B'],
            ['E', 'F', 'D'], ['C', 'D', 'E'], ['A', 'B', 'F'],
        ],
        7 => [
            ['A', 'G', 'D'], ['B', 'F', 'C'], ['C', 'E', 'G'],
            ['A', 'D', 'B'], ['B', 'G', 'E'], ['C', 'F', 'A'],
            ['D', 'E', 'G'], ['A', 'F', 'B'], ['B', 'E', 'F'],
            ['C', 'G', 'D'], ['D', 'F', 'C'], ['A', 'E', 'B'],
            ['B', 'C', 'E'], ['D', 'G', 'F'], ['E', 'F', 'G'],
            ['A', 'C', 'D'], ['B', 'D', 'C'], ['E', 'G', 'A'],
            ['C', 'D', 'E'], ['F', 'G', 'A'], ['A', 'B', 'F'],
        ],
        8 => [
            ['A', 'H', 'C'], ['B', 'G', 'F'], ['C', 'F', 'H'],
            ['D', 'E', 'G'], ['A', 'G', 'D'], ['F', 'H', 'E'],
            ['B', 'E', 'A'], ['C', 'D', 'B'], ['A', 'F', 'C'],
            ['E', 'G', 'H'], ['D', 'H', 'F'], ['B', 'C', 'E'],
            ['A', 'E', 'D'], ['D', 'F', 'G'], ['C', 'G', 'A'],
            ['B', 'H', 'F'], ['A', 'D', 'E'], ['C', 'E', 'H'],
            ['B', 'F', 'D'], ['G', 'H', 'C'], ['A', 'C', 'F'],
            ['B', 'D', 'G'], ['E', 'H', 'A'], ['F', 'G', 'B'],
            ['C', 'H', 'E'], ['D', 'G', 'F'], ['E', 'F', 'C'],
            ['A', 'B', 'D'],
        ],
    ];

    public const TAILLE_MIN = 3;
    public const TAILLE_MAX = 8;

    /**
     * Ordre des matchs pour une poule de $taille joueurs.
     *
     * @return list<array{0:string,1:string,2:string}> [joueur1, joueur2, arbitre]
     */
    public static function pour(int $taille): array
    {
        if (!isset(self::SEQUENCES[$taille])) {
            throw new InvalidArgumentException(sprintf(
                'Aucune sequence connue pour une poule de %d joueurs (attendu %d a %d).',
                $taille,
                self::TAILLE_MIN,
                self::TAILLE_MAX
            ));
        }

        return self::SEQUENCES[$taille];
    }

    /** Nombre de matchs d'une poule de $taille joueurs. */
    public static function nombreMatchs(int $taille): int
    {
        return intdiv($taille * ($taille - 1), 2);
    }

    /** Indice (base 0) correspondant a une lettre de poule : A => 0. */
    public static function indice(string $lettre): int
    {
        return ord($lettre) - ord('A');
    }

    /** @return list<int> tailles de poule couvertes */
    public static function taillesConnues(): array
    {
        return array_keys(self::SEQUENCES);
    }
}
