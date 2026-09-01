<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Generation;

use RMCF\Tournois\Domain\OrdreMatchs;

/**
 * L'ordre des parties d'une poule (annexe A).
 *
 * Deux regimes :
 *
 *   - taille 3 a 8 : les sequences OFFICIELLES, deja eprouvees par le
 *     club et reprises telles quelles de `Domain\OrdreMatchs`. Elles
 *     equilibrent les temps de repos et designent l'arbitre, ce qu'aucun
 *     algorithme generique ne fait aussi bien ;
 *   - autres tailles : un repli sur la ronde de Berger (methode du
 *     cercle), qui garantit qu'aucun joueur ne joue deux parties
 *     consecutives mais ne designe pas d'arbitre.
 *
 * REGLE ITTF 3.7.5.5 (`derniere_partie_decisive`) — la derniere partie
 * de la poule devrait opposer les deux joueurs susceptibles de se
 * disputer la qualification : les tetes de serie 1 et 2 si un seul se
 * qualifie, 2 et 3 si deux se qualifient. Cout nul, gain reel : la
 * partie decisive tombe a la fin plutot qu'au milieu. Le reordonnancement
 * se fait ici, sur la sequence deja produite.
 */
final class OrdreParties
{
    /**
     * Sequence d'une poule de `$taille` places, en lettres A, B, C…
     *
     * @return list<array{0:string,1:string,2:?string}> [camp A, camp B, arbitre]
     */
    public static function pour(int $taille): array
    {
        if ($taille < 2) {
            return [];
        }

        if ($taille >= OrdreMatchs::TAILLE_MIN && $taille <= OrdreMatchs::TAILLE_MAX) {
            return array_map(
                static fn (array $m): array => [$m[0], $m[1], $m[2]],
                OrdreMatchs::pour($taille)
            );
        }

        return self::berger($taille);
    }

    /**
     * Reordonne pour que la partie decisive tombe en dernier (ITTF 3.7.5.5).
     *
     * `$nbQualifies` vaut 1 (on cherche A vs B) ou 2 et plus (on cherche
     * B vs C, la partie qui decide de la derniere place qualificative).
     * Si la partie visee est deja la derniere, rien ne bouge ; sinon on
     * l'extrait et on la repousse en fin de sequence.
     *
     * @param  list<array{0:string,1:string,2:?string}> $sequence
     * @return list<array{0:string,1:string,2:?string}>
     */
    public static function decisiveEnDernier(array $sequence, int $taille, int $nbQualifies): array
    {
        if ($sequence === [] || $taille < 3) {
            return $sequence;
        }

        $premier = self::lettre($nbQualifies <= 1 ? 0 : $nbQualifies - 1);
        $second  = self::lettre($nbQualifies <= 1 ? 1 : $nbQualifies);

        if (self::indiceLettre($second) >= $taille) {
            return $sequence;
        }

        $cible = null;

        foreach ($sequence as $i => $partie) {
            $paire = [$partie[0], $partie[1]];
            sort($paire);

            if ($paire === [$premier, $second]) {
                $cible = $i;
                break;
            }
        }

        if ($cible === null || $cible === count($sequence) - 1) {
            return $sequence;
        }

        $partie = $sequence[$cible];
        unset($sequence[$cible]);

        return [...array_values($sequence), $partie];
    }

    /** Nombre de parties d'une poule complete : n(n-1)/2. */
    public static function nombreParties(int $taille): int
    {
        return $taille < 2 ? 0 : intdiv($taille * ($taille - 1), 2);
    }

    /** Lettre de la place `$indice` (0 => A). */
    public static function lettre(int $indice): string
    {
        return chr(ord('A') + $indice);
    }

    public static function indiceLettre(string $lettre): int
    {
        return ord(strtoupper($lettre)) - ord('A');
    }

    /**
     * Ronde de Berger — repli pour les tailles hors 3..8.
     *
     * Methode du cercle : on fixe le dernier joueur et on fait tourner
     * les autres. Avec un effectif impair on ajoute un joueur fictif,
     * dont l'adversaire du tour est simplement au repos.
     *
     * @return list<array{0:string,1:string,2:?string}>
     */
    private static function berger(int $taille): array
    {
        $impair  = $taille % 2 === 1;
        $places  = $impair ? $taille + 1 : $taille;
        $lettres = [];

        for ($i = 0; $i < $places; $i++) {
            $lettres[] = $i < $taille ? self::lettre($i) : null;
        }

        $sequence = [];
        $rotation = $lettres;
        $fixe     = array_pop($rotation);

        for ($tour = 0; $tour < $places - 1; $tour++) {
            $ordre = [...$rotation, $fixe];

            for ($i = 0; $i < intdiv($places, 2); $i++) {
                $a = $ordre[$i];
                $b = $ordre[$places - 1 - $i];

                if ($a === null || $b === null) {
                    continue;
                }

                $sequence[] = [$a, $b, null];
            }

            array_unshift($rotation, array_pop($rotation));
        }

        return $sequence;
    }
}
