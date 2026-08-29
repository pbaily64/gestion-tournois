<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

/**
 * Classement des joueurs d'une poule.
 *
 * LES CRITERES DEPENDENT DU FORMAT DE JEU
 *
 * En TROIS SETS SECS, tout match compte exactement trois sets et se
 * joue jusqu'au bout : le nombre de victoires n'entre pas en ligne de
 * compte, ce sont les sets qui font foi.
 *
 *   1. sets gagnes
 *   2. difference de sets
 *   3. confrontation directe entre les joueurs encore a egalite
 *   4. egalite irreductible : la decision revient a l'organisateur
 *
 * Un joueur peut donc terminer devant un adversaire qui a remporte un
 * match de plus que lui, si son bilan en sets est meilleur.
 *
 * En DEUX OU TROIS SETS GAGNANTS, un match s'arrete des qu'il est
 * gagne : le nombre de victoires reprend son sens et vient en tete.
 *
 *   1. nombre de victoires
 *   2. sets gagnes
 *   3. difference de sets
 *   4. confrontation directe
 *   5. egalite irreductible
 *
 * Dans les deux cas, la confrontation directe intervient en dernier
 * recours avant l'arbitrage de l'organisateur. A plus de deux joueurs,
 * elle prend la forme d'un sous-championnat restreint : on ne retient
 * que les matchs qu'ils ont disputes entre eux.
 *
 * La methode est recursive : si un critere scinde un groupe en
 * sous-groupes encore a egalite, chacun est traite de la meme facon.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class ClassementPoule
{
    /**
     * Classe les joueurs d'une poule.
     *
     * @param list<string>                                                   $joueurs lettres ou identifiants
     * @param list<array{a:string,b:string,sets_a:int,sets_b:int,points_a?:int,points_b?:int}> $matchs
     *
     * @return list<array{
     *     joueur:string, place:int, groupe:int, victoires:int, defaites:int,
     *     sets_pour:int, sets_contre:int, diff:int, ex_aequo:bool
     * }>
     *
     * `groupe` identifie les joueurs qu'aucun critere ne separe : ils
     * partagent la meme valeur et devront etre departages a la main.
     */
    public static function classer(array $joueurs, array $matchs, FormatMatch $format): array
    {
        $stats = self::statistiques($joueurs, $matchs);
        $blocs = [$joueurs];

        // Criteres generaux, appliques a toute la poule.
        foreach (self::criteresGeneraux($format) as $critere) {
            $suivants = [];

            foreach ($blocs as $bloc) {
                foreach (self::grouper($bloc, static fn (string $j): int => $critere($stats[$j])) as $sousBloc) {
                    $suivants[] = $sousBloc;
                }
            }

            $blocs = $suivants;
        }

        // Ce qui reste a egalite se departage en confrontation directe.
        $ordonnes = [];

        foreach ($blocs as $bloc) {
            foreach (self::departager($bloc, $matchs) as $sousBloc) {
                $ordonnes[] = $sousBloc;
            }
        }

        $resultat = [];
        $place    = 1;
        $groupe   = 0;

        foreach ($ordonnes as $bloc) {
            $exAequo = count($bloc) > 1;
            $groupe++;

            foreach ($bloc as $joueur) {
                $resultat[] = [
                    'joueur'      => $joueur,
                    'place'       => $place,
                    'groupe'      => $groupe,
                    'victoires'   => $stats[$joueur]['victoires'],
                    'defaites'    => $stats[$joueur]['defaites'],
                    'sets_pour'   => $stats[$joueur]['sets_pour'],
                    'sets_contre' => $stats[$joueur]['sets_contre'],
                    'diff'        => $stats[$joueur]['sets_pour'] - $stats[$joueur]['sets_contre'],
                    'ex_aequo'    => $exAequo,
                ];
                $place++;
            }
        }

        return $resultat;
    }

    /**
     * Criteres appliques a l'ensemble de la poule, avant la
     * confrontation directe.
     *
     * @return list<callable(array<string,int>):int>
     */
    private static function criteresGeneraux(FormatMatch $format): array
    {
        $criteres = [];

        // En trois sets secs, tous les matchs vont au bout : le nombre
        // de victoires n'est pas un critere.
        if (!$format->setsComparables()) {
            $criteres[] = static fn (array $s): int => $s['victoires'];
        }

        $criteres[] = static fn (array $s): int => $s['sets_pour'];
        $criteres[] = static fn (array $s): int => $s['sets_pour'] - $s['sets_contre'];

        return $criteres;
    }

    /**
     * Sous-championnat : departage un groupe de joueurs a egalite.
     *
     * Retourne une liste de blocs ordonnes. Un bloc de plus d'un joueur
     * signale une egalite que la regle ne tranche pas.
     *
     * @param  list<string>                                                   $groupe
     * @param  list<array{a:string,b:string,sets_a:int,sets_b:int,points_a?:int,points_b?:int}> $matchs
     * @return list<list<string>>
     */
    private static function departager(array $groupe, array $matchs): array
    {
        if (count($groupe) <= 1) {
            return [$groupe];
        }

        // Seuls les matchs entre joueurs du groupe comptent.
        $restreints = array_values(array_filter(
            $matchs,
            static fn (array $m): bool => in_array($m['a'], $groupe, true)
                && in_array($m['b'], $groupe, true)
        ));

        if ($restreints === []) {
            return [$groupe];
        }

        $stats = self::statistiques($groupe, $restreints);

        foreach (self::criteresConfrontation() as $critere) {
            $blocs = self::grouper($groupe, static fn (string $j): int => $critere($stats[$j]));

            // Le critere a-t-il scinde le groupe ?
            if (count($blocs) > 1) {
                $sortie = [];

                foreach ($blocs as $bloc) {
                    foreach (self::departager($bloc, $restreints) as $sousBloc) {
                        $sortie[] = $sousBloc;
                    }
                }

                return $sortie;
            }
        }

        // Aucun critere ne separe : egalite irreductible.
        return [$groupe];
    }

    /**
     * Criteres du sous-championnat, dans l'ordre d'application.
     *
     * Restreint aux matchs que les joueurs a egalite ont disputes entre
     * eux : a deux, cela se ramene a la confrontation directe.
     *
     * @return list<callable(array<string,int>):int>
     */
    private static function criteresConfrontation(): array
    {
        return [
            static fn (array $s): int => $s['victoires'],
            static fn (array $s): int => $s['sets_pour'] - $s['sets_contre'],
            static fn (array $s): int => $s['points_pour'] - $s['points_contre'],
        ];
    }

    /**
     * Totaux d'un ensemble de joueurs sur un ensemble de matchs.
     *
     * @param  list<string>                                                   $joueurs
     * @param  list<array{a:string,b:string,sets_a:int,sets_b:int,points_a?:int,points_b?:int}> $matchs
     * @return array<string, array{victoires:int,defaites:int,sets_pour:int,sets_contre:int,points_pour:int,points_contre:int}>
     */
    public static function statistiques(array $joueurs, array $matchs): array
    {
        $stats = [];

        foreach ($joueurs as $j) {
            $stats[$j] = [
                'victoires'     => 0,
                'defaites'      => 0,
                'sets_pour'     => 0,
                'sets_contre'   => 0,
                'points_pour'   => 0,
                'points_contre' => 0,
            ];
        }

        foreach ($matchs as $m) {
            foreach ([[$m['a'], $m['b'], 'a', 'b'], [$m['b'], $m['a'], 'b', 'a']] as [$moi, , $cleMoi, $cleLui]) {
                if (!isset($stats[$moi])) {
                    continue;
                }

                $setsMoi = $m['sets_' . $cleMoi];
                $setsLui = $m['sets_' . $cleLui];

                $stats[$moi]['sets_pour']   += $setsMoi;
                $stats[$moi]['sets_contre'] += $setsLui;

                $stats[$moi]['points_pour']   += $m['points_' . $cleMoi] ?? 0;
                $stats[$moi]['points_contre'] += $m['points_' . $cleLui] ?? 0;

                if ($setsMoi > $setsLui) {
                    $stats[$moi]['victoires']++;
                } elseif ($setsMoi < $setsLui) {
                    $stats[$moi]['defaites']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Regroupe les joueurs par valeur d'un critere, du meilleur au moins
     * bon. Tous les criteres du classement se lisent en decroissant :
     * plus de victoires, meilleure difference.
     *
     * @param  list<string>         $joueurs
     * @param  callable(string):int $valeur
     * @return list<list<string>>   blocs ordonnes
     */
    private static function grouper(array $joueurs, callable $valeur): array
    {
        $par = [];

        foreach ($joueurs as $j) {
            $par[$valeur($j)][] = $j;
        }

        krsort($par);

        return array_values($par);
    }
}
