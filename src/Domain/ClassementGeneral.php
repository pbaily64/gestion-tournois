<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

/**
 * Classement general des participants a l'issue des poules.
 *
 * Ce classement est TRANSVERSAL : il ordonne tous les joueurs de la
 * phase, toutes poules confondues. Il determine les qualifications, la
 * composition des barrages et la repartition dans les tableaux.
 *
 * Il ne peut donc PAS reposer sur des confrontations directes : deux
 * joueurs de poules differentes ne se sont pas rencontres. C'est ce qui
 * le distingue du classement de poule (voir ClassementPoule).
 *
 * CRITERES, en trois sets secs
 *
 *   1. place obtenue en poule, croissante — tous les premiers de poule
 *      devant tous les deuxiemes, et ainsi de suite
 *   2. nombre de victoires, decroissant
 *   3. sets gagnes, decroissant
 *   4. difference de sets, decroissante
 *
 * L'ordre « sets gagnes avant difference de sets » est un choix assume
 * et publie sur la feuille ATTRIBUTION DES POINTS, non un artefact
 * d'implementation. Il doit etre reproduit a l'identique.
 *
 * POINT OUVERT, en sets gagnants
 *
 * Les criteres 3 et 4 supposent que chaque match compte le meme nombre
 * de sets. En deux ou trois sets gagnants ce n'est plus le cas : un
 * joueur qui l'emporte peniblement 3-2 accumule plus de sets que celui
 * qui ecrase 3-0. Le critere de remplacement n'est pas arrete (dossier
 * de conception, fin de section 4.5 et tableau de section 9).
 *
 * En attendant, le classement s'arrete apres les victoires et les
 * egalites restantes sont signalees a l'organisateur, qui tranche. Cela
 * vaut mieux qu'appliquer silencieusement une regle inadaptee.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class ClassementGeneral
{
    /**
     * Rang attribue a un joueur dont la place en poule n'est pas
     * calculee — poule inachevee, ou joueur non affecte. Il passe en
     * queue de classement plutot que de remonter en tete.
     *
     * Une valeur entiere et non PHP_INT_MAX / 2 : en PHP, la division
     * rend toujours un flottant, meme entre deux entiers.
     */
    private const PLACE_ABSENTE = 9999;

    /**
     * Ordonne les participants d'une phase.
     *
     * @param list<array{
     *     id:int, place_poule:?int, victoires:int,
     *     sets_pour:int, sets_contre:int
     * }> $participants
     *
     * @return list<array{
     *     id:int, place:int, place_poule:?int, victoires:int,
     *     sets_pour:int, sets_contre:int, diff:int, ex_aequo:bool
     * }>
     */
    public static function classer(array $participants, FormatMatch $format): array
    {
        $blocs = [$participants];

        foreach (self::criteres($format) as $critere) {
            $suivants = [];

            foreach ($blocs as $bloc) {
                foreach (self::scinder($bloc, $critere) as $sousBloc) {
                    $suivants[] = $sousBloc;
                }
            }

            $blocs = $suivants;
        }

        $resultat = [];
        $place    = 1;

        foreach ($blocs as $bloc) {
            $exAequo = count($bloc) > 1;

            foreach ($bloc as $p) {
                $resultat[] = [
                    'id'          => $p['id'],
                    'place'       => $place,
                    'place_poule' => $p['place_poule'],
                    'victoires'   => $p['victoires'],
                    'sets_pour'   => $p['sets_pour'],
                    'sets_contre' => $p['sets_contre'],
                    'diff'        => $p['sets_pour'] - $p['sets_contre'],
                    'ex_aequo'    => $exAequo,
                ];
                $place++;
            }
        }

        return $resultat;
    }

    /**
     * Y a-t-il des egalites que la regle ne tranche pas ?
     *
     * @param list<array{ex_aequo:bool}> $classement
     */
    public static function comporteDesEgalites(array $classement): bool
    {
        foreach ($classement as $ligne) {
            if ($ligne['ex_aequo']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Criteres applicables, dans l'ordre.
     *
     * Chaque critere rend une valeur entiere ; le tri est toujours
     * decroissant, les criteres croissants sont donc negatifs.
     *
     * @return list<callable(array<string,mixed>):int>
     */
    private static function criteres(FormatMatch $format): array
    {
        $criteres = [
            // Place en poule, croissante : une place non calculee passe
            // en dernier plutot que de remonter en tete.
            static fn (array $p): int => -($p['place_poule'] ?? self::PLACE_ABSENTE),

            static fn (array $p): int => $p['victoires'],
        ];

        if (!$format->setsComparables()) {
            // Sets non comparables : on s'arrete la, point ouvert.
            return $criteres;
        }

        $criteres[] = static fn (array $p): int => $p['sets_pour'];
        $criteres[] = static fn (array $p): int => $p['sets_pour'] - $p['sets_contre'];

        return $criteres;
    }

    /**
     * Scinde un bloc selon un critere, du meilleur au moins bon.
     *
     * @param  list<array<string,mixed>>              $bloc
     * @param  callable(array<string,mixed>):int      $critere
     * @return list<list<array<string,mixed>>>
     */
    private static function scinder(array $bloc, callable $critere): array
    {
        if (count($bloc) <= 1) {
            return [$bloc];
        }

        $par = [];

        foreach ($bloc as $p) {
            $par[(int) $critere($p)][] = $p;
        }

        krsort($par);

        return array_values($par);
    }
}
