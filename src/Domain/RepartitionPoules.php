<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

/**
 * Repartitions possibles d'un effectif en poules.
 *
 * Le choix du nombre de poules revient a l'organisateur : c'est une
 * decision de terrain, qui depend de l'heure, du nombre de tables et de
 * ce qu'il veut offrir aux joueurs. Cette classe ne decide rien ; elle
 * enumere les options viables et chiffre leurs consequences, pour que
 * l'arbitrage se fasse en connaissance de cause.
 *
 * Le compromis est toujours le meme : moins de poules donne plus de
 * matchs par joueur, donc une soiree plus longue ; plus de poules
 * raccourcit la soiree mais chacun joue moins.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class RepartitionPoules
{
    /**
     * Enumere les repartitions viables, de la plus fournie en matchs a
     * la plus courte.
     *
     * @return list<array{
     *     nb_poules:int, tailles:list<int>, nb_matchs:int,
     *     matchs_min:int, matchs_max:int, composition:string
     * }>
     */
    public static function options(int $nbJoueurs): array
    {
        $options = [];

        for ($p = Serpentin::POULES_MIN; $p <= Serpentin::POULES_MAX; $p++) {
            $tailles = self::tailles($nbJoueurs, $p);

            if ($tailles === null) {
                continue;
            }

            $options[] = [
                'nb_poules'   => $p,
                'tailles'     => $tailles,
                'nb_matchs'   => self::nombreMatchs($tailles),
                'matchs_min'  => min($tailles) - 1,
                'matchs_max'  => max($tailles) - 1,
                'composition' => self::composition($tailles),
            ];
        }

        return $options;
    }

    /**
     * Tailles des poules pour un effectif et un nombre de poules donnes,
     * ou null si la repartition sort des bornes admises (3 a 8 joueurs).
     *
     * Les poules les plus fournies viennent en tete, ce qui correspond a
     * l'ordre du serpentin : la poule A recoit le premier joueur.
     *
     * @return list<int>|null
     */
    public static function tailles(int $nbJoueurs, int $nbPoules): ?array
    {
        if ($nbPoules < Serpentin::POULES_MIN || $nbPoules > Serpentin::POULES_MAX || $nbJoueurs < 1) {
            return null;
        }

        $base  = intdiv($nbJoueurs, $nbPoules);
        $reste = $nbJoueurs % $nbPoules;

        $tailles = array_merge(
            array_fill(0, $reste, $base + 1),
            array_fill(0, $nbPoules - $reste, $base)
        );

        if (min($tailles) < Serpentin::JOUEURS_PAR_POULE_MIN
            || max($tailles) > Serpentin::JOUEURS_PAR_POULE_MAX
        ) {
            return null;
        }

        return array_values($tailles);
    }

    /**
     * Nombre total de matchs, somme des n(n-1)/2 de chaque poule.
     *
     * @param list<int> $tailles
     */
    public static function nombreMatchs(array $tailles): int
    {
        $total = 0;

        foreach ($tailles as $t) {
            $total += intdiv($t * ($t - 1), 2);
        }

        return $total;
    }

    /**
     * Libelle lisible : « 2 poules de 5 + 4 poules de 4 ».
     *
     * @param list<int> $tailles
     */
    public static function composition(array $tailles): string
    {
        $compte = array_count_values($tailles);
        krsort($compte);

        $parts = [];

        foreach ($compte as $taille => $nb) {
            $parts[] = $nb === 1
                ? sprintf('1 poule de %d', $taille)
                : sprintf('%d poules de %d', $nb, $taille);
        }

        return implode(' + ', $parts);
    }

    /** La repartition demandee est-elle realisable ? */
    public static function estPossible(int $nbJoueurs, int $nbPoules): bool
    {
        return self::tailles($nbJoueurs, $nbPoules) !== null;
    }
}
