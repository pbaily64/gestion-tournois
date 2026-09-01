<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Rencontre;

use RMCF\Tournois\Formule\Expression;

/**
 * Deroule d'une rencontre : quelles parties se jouent encore, et quand
 * la rencontre est acquise.
 *
 * RG-11 — en arret a l'acquis, les parties non disputees sont
 * enregistrees avec le motif « non_disputee » et ne comptent dans AUCUN
 * quotient. Elles sont a distinguer d'un forfait (RG-82) : le forfait
 * est une defaite, la partie non disputee n'est rien du tout. Les
 * confondre fausse silencieusement tous les quotients de manches.
 *
 * PARTIE CONDITIONNELLE
 *
 * Le double decisif d'un duo ne se joue qu'a une victoire partout. La
 * condition est une EXPRESSION evaluee sur les victoires acquises, donc
 * une donnee : « victoires_a = 1 et victoires_b = 1 ». La forme
 * abregee « score = 1-1 » est admise et traduite.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class DerouleRencontre
{
    /**
     * Etat d'une rencontre a partir des resultats deja enregistres.
     *
     * @param  list<string> $resultats 'a' ou 'b' selon le vainqueur, dans l'ordre des parties
     * @return array{
     *     victoires_a:int, victoires_b:int, terminee:bool, vainqueur:?string,
     *     a_jouer:list<array<string,mixed>>, non_disputees:list<array<string,mixed>>
     * }
     */
    public static function etat(SystemeRencontre $systeme, array $resultats): array
    {
        $victoiresA = 0;
        $victoiresB = 0;

        foreach ($resultats as $resultat) {
            $resultat === 'a' ? $victoiresA++ : $victoiresB++;
        }

        $seuil    = $systeme->victoiresPourGagner();
        $acquise  = $systeme->sArreteALAcquis()
            && ($victoiresA >= $seuil || $victoiresB >= $seuil);

        $aJouer       = [];
        $nonDisputees = [];

        foreach (array_slice($systeme->parties, count($resultats)) as $partie) {
            if ($acquise) {
                $nonDisputees[] = $partie;

                continue;
            }

            if (($partie['conditionnelle'] ?? false)
                && !self::conditionRemplie($partie['condition'] ?? null, $victoiresA, $victoiresB, $resultats, $systeme)
            ) {
                $nonDisputees[] = $partie;

                continue;
            }

            $aJouer[] = $partie;
        }

        $terminee = $aJouer === [];

        return [
            'victoires_a'   => $victoiresA,
            'victoires_b'   => $victoiresB,
            'terminee'      => $terminee,
            'vainqueur'     => $terminee ? self::vainqueur($victoiresA, $victoiresB) : null,
            'a_jouer'       => $aJouer,
            'non_disputees' => $nonDisputees,
        ];
    }

    /**
     * Prochaine partie a lancer, ou null si la rencontre est acquise.
     *
     * @param  list<string> $resultats
     * @return array<string,mixed>|null
     */
    public static function prochainePartie(SystemeRencontre $systeme, array $resultats): ?array
    {
        $etat = self::etat($systeme, $resultats);

        return $etat['a_jouer'][0] ?? null;
    }

    /**
     * La condition d'une partie conditionnelle est-elle remplie ?
     *
     * @param list<string> $resultats
     */
    private static function conditionRemplie(
        ?string $condition,
        int $victoiresA,
        int $victoiresB,
        array $resultats,
        SystemeRencontre $systeme,
    ): bool {
        // Une partie conditionnelle ne s'evalue qu'une fois toutes les
        // parties fermes disputees.
        if (count($resultats) < $systeme->nbPartiesFermes()) {
            return false;
        }

        if ($condition === null || trim($condition) === '') {
            return $victoiresA === $victoiresB;
        }

        return Expression::evaluerCondition(
            self::normaliser($condition),
            [
                'victoires_a' => $victoiresA,
                'victoires_b' => $victoiresB,
                'ecart'       => $victoiresA - $victoiresB,
            ]
        );
    }

    /**
     * Traduit la forme abregee « score = 1-1 » en expression.
     *
     * Les organisateurs l'ecrivent ainsi, c'est le vocabulaire du
     * reglement ; la refuser au profit d'une syntaxe informatique
     * serait un mauvais service.
     */
    private static function normaliser(string $condition): string
    {
        if (preg_match('/^\s*score\s*=\s*(\d+)\s*-\s*(\d+)\s*$/i', $condition, $trouve) === 1) {
            return sprintf(
                'victoires_a = %d et victoires_b = %d',
                (int) $trouve[1],
                (int) $trouve[2]
            );
        }

        return $condition;
    }

    private static function vainqueur(int $victoiresA, int $victoiresB): ?string
    {
        return match (true) {
            $victoiresA > $victoiresB => 'a',
            $victoiresB > $victoiresA => 'b',
            default                   => null,
        };
    }
}
