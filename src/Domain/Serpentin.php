<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

use InvalidArgumentException;

/**
 * Repartition des participants en poules selon la regle du S (serpentin).
 *
 * Voir dossier de conception, section 4.3.
 *
 * Le classeur Excel stockait sept sequences pre-remplies dans la feuille
 * ORDRE MATCH (colonnes BF a BL, 48 lignes). Cette classe les calcule,
 * ce qui supprime la limite de 48 joueurs et de 8 poules.
 *
 * Pour le participant d'indice i (base 0) et n poules :
 *     p = i mod 2n
 *     poule = p          si p < n
 *     poule = 2n - 1 - p sinon
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class Serpentin
{
    public const POULES_MIN = 2;
    public const POULES_MAX = 8;
    public const JOUEURS_PAR_POULE_MIN = 3;
    public const JOUEURS_PAR_POULE_MAX = 8;

    /**
     * Indice de poule (base 0) du participant d'indice $rang (base 0).
     */
    public static function pouleDe(int $rang, int $nbPoules): int
    {
        if ($rang < 0) {
            throw new InvalidArgumentException('Le rang ne peut pas etre negatif.');
        }

        self::verifierNbPoules($nbPoules);

        $p = $rang % (2 * $nbPoules);

        return $p < $nbPoules ? $p : (2 * $nbPoules - 1 - $p);
    }

    /**
     * Repartit une liste ordonnee de participants.
     *
     * La liste doit etre triee au prealable : classement decroissant
     * (du meilleur au moins bon, non classes en fin), puis ordre
     * alphabetique a classement egal.
     *
     * @template T
     * @param  list<T>              $participants
     * @return array<int, list<T>>  indice de poule => participants
     */
    public static function repartir(array $participants, int $nbPoules): array
    {
        self::verifierNbPoules($nbPoules);

        $poules = array_fill(0, $nbPoules, []);

        foreach (array_values($participants) as $rang => $participant) {
            $poules[self::pouleDe($rang, $nbPoules)][] = $participant;
        }

        return $poules;
    }

    /**
     * Libelle d'une poule : 0 => A, 1 => B, ...
     */
    public static function libelle(int $indicePoule): string
    {
        if ($indicePoule < 0) {
            throw new InvalidArgumentException('Indice de poule negatif.');
        }

        return chr(ord('A') + $indicePoule);
    }

    /**
     * Controles herites du VBA : 2 a 8 poules, 3 a 8 joueurs par poule.
     * Les effectifs peuvent etre inegaux.
     *
     * @return list<string> liste vide si la repartition est valide
     */
    public static function erreurs(int $nbParticipants, int $nbPoules): array
    {
        $erreurs = [];

        if ($nbPoules < self::POULES_MIN || $nbPoules > self::POULES_MAX) {
            $erreurs[] = sprintf(
                'Le nombre de poules doit etre compris entre %d et %d.',
                self::POULES_MIN,
                self::POULES_MAX
            );

            return $erreurs;
        }

        $min = intdiv($nbParticipants, $nbPoules);
        $max = $nbParticipants % $nbPoules === 0 ? $min : $min + 1;

        if ($min < self::JOUEURS_PAR_POULE_MIN) {
            $erreurs[] = sprintf(
                'Avec %d participants en %d poules, une poule n\'aurait que %d joueur(s) ; le minimum est %d.',
                $nbParticipants,
                $nbPoules,
                $min,
                self::JOUEURS_PAR_POULE_MIN
            );
        }

        if ($max > self::JOUEURS_PAR_POULE_MAX) {
            $erreurs[] = sprintf(
                'Avec %d participants en %d poules, une poule compterait %d joueurs ; le maximum est %d.',
                $nbParticipants,
                $nbPoules,
                $max,
                self::JOUEURS_PAR_POULE_MAX
            );
        }

        return $erreurs;
    }

    private static function verifierNbPoules(int $nbPoules): void
    {
        if ($nbPoules < self::POULES_MIN || $nbPoules > self::POULES_MAX) {
            throw new InvalidArgumentException(sprintf(
                'Nombre de poules invalide (%d) : attendu entre %d et %d.',
                $nbPoules,
                self::POULES_MIN,
                self::POULES_MAX
            ));
        }
    }
}
