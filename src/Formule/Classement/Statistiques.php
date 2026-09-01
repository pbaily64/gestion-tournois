<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

/**
 * Calcul des bilans a partir des faits enregistres.
 *
 * FORME D'UNE PARTIE
 *
 *   [
 *     'a' => 'DUPONT', 'b' => 'MARTIN',
 *     'manches_a' => 3, 'manches_b' => 1,
 *     'points_a'  => 41, 'points_b' => 29,     (facultatif)
 *     'motif_fin' => 'normal',                 (facultatif)
 *     'forfait'   => null,                     ('a' ou 'b' si forfait)
 *   ]
 *
 * MOTIFS DE FIN, ET POURQUOI ILS NE SE COMPTENT PAS PAREIL (RG-82)
 *
 *   normal        partie disputee jusqu'a son terme
 *   abandon       abandon en cours : le score ACQUIS est conserve
 *                 (regle FRBTT, RG-81) — la partie compte normalement
 *   forfait       le camp declare forfait ne marque pas de point de
 *                 rencontre, mais la partie compte comme jouee pour son
 *                 adversaire
 *   non_disputee  partie non jouee parce que la rencontre etait acquise
 *                 (RG-11) — elle ne compte dans AUCUN quotient
 *
 * Confondre les deux derniers est la premiere source de classements
 * faux : c'est pourquoi ils sont distincts jusque dans le stockage.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class Statistiques
{
    /**
     * @param  list<string>              $entites
     * @param  list<array<string,mixed>> $parties
     * @return array<string,Bilan>
     */
    public static function calculer(array $entites, array $parties, BaremeRencontre $bareme): array
    {
        $bilans = [];

        foreach ($entites as $entite) {
            $bilans[$entite] = new Bilan();
        }

        foreach ($parties as $partie) {
            $motif = (string) ($partie['motif_fin'] ?? 'normal');

            if ($motif === 'non_disputee') {
                continue; // RG-11 : ne compte dans aucun quotient
            }

            $a = (string) $partie['a'];
            $b = (string) $partie['b'];

            self::porter($bilans, $a, $b, $partie, 'a', 'b', $bareme);
            self::porter($bilans, $b, $a, $partie, 'b', 'a', $bareme);
        }

        return $bilans;
    }

    /**
     * Ne conserve que les parties disputees entre les entites donnees.
     *
     * C'est le sous-championnat de la portee « entre ex aequo ».
     *
     * @param  list<array<string,mixed>> $parties
     * @param  list<string>              $entites
     * @return list<array<string,mixed>>
     */
    public static function restreindre(array $parties, array $entites): array
    {
        return array_values(array_filter(
            $parties,
            static fn (array $p): bool => in_array((string) $p['a'], $entites, true)
                && in_array((string) $p['b'], $entites, true)
        ));
    }

    /**
     * Buchholz : somme des points de rencontre des adversaires
     * rencontres. Reserve au systeme suisse (critere 17).
     *
     * @param  array<string,Bilan> $bilans
     * @return array<string,float>
     */
    public static function buchholz(array $bilans): array
    {
        $sommes = [];

        foreach ($bilans as $entite => $bilan) {
            $somme = 0.0;

            foreach ($bilan->adversaires as $adversaire) {
                $somme += $bilans[$adversaire]->pointsRencontre ?? 0;
            }

            $sommes[$entite] = $somme;
        }

        return $sommes;
    }

    /**
     * Impute une partie au bilan d'un camp.
     *
     * @param array<string,Bilan>  $bilans
     * @param array<string,mixed>  $partie
     */
    private static function porter(
        array &$bilans,
        string $moi,
        string $adversaire,
        array $partie,
        string $cleMoi,
        string $cleLui,
        BaremeRencontre $bareme,
    ): void {
        if (!isset($bilans[$moi])) {
            return;
        }

        $bilan     = $bilans[$moi];
        $manchesMoi = (int) ($partie['manches_' . $cleMoi] ?? 0);
        $manchesLui = (int) ($partie['manches_' . $cleLui] ?? 0);
        $forfait    = $partie['forfait'] ?? null;

        $bilan->parties++;
        $bilan->adversaires[] = $adversaire;
        $bilan->manchesPour   += $manchesMoi;
        $bilan->manchesContre += $manchesLui;
        $bilan->pointsPour    += (int) ($partie['points_' . $cleMoi] ?? 0);
        $bilan->pointsContre  += (int) ($partie['points_' . $cleLui] ?? 0);

        if ($forfait === $cleMoi) {
            $bilan->forfaitsSubis++;
            $bilan->defaites++;
            $bilan->pointsRencontre += $bareme->forfait;

            return;
        }

        if ($manchesMoi > $manchesLui) {
            $bilan->victoires++;
            $bilan->pointsRencontre += $bareme->victoire;

            return;
        }

        if ($manchesMoi < $manchesLui) {
            $bilan->defaites++;
            $bilan->pointsRencontre += $bareme->defaiteJouee;

            return;
        }

        // Egalite : possible en manches seches en nombre pair (RG-52).
        $bilan->nuls++;
        $bilan->pointsRencontre += $bareme->nul;
    }
}
