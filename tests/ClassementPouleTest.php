<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\ClassementPoule;
use RMCF\Tournois\Domain\FormatMatch;

final class ClassementPouleTest extends TestCase
{
    /**
     * Poule E de MbN4, relevee sur la feuille du classeur.
     *
     * Trois joueurs a trois victoires : Rossomme (A), Cortvriendt (B) et
     * Laroche (C). Chacun a battu l'un des deux autres et perdu contre le
     * troisieme : aucune confrontation directe ne les departage. Le
     * sous-championnat donne +2, 0 et -2.
     *
     * @return list<array{a:string,b:string,sets_a:int,sets_b:int}>
     */
    private static function pouleE(): array
    {
        return [
            ['a' => 'A', 'b' => 'E', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'B', 'b' => 'D', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'C', 'b' => 'E', 'sets_a' => 2, 'sets_b' => 1],
            ['a' => 'A', 'b' => 'D', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'B', 'b' => 'C', 'sets_a' => 1, 'sets_b' => 2],
            ['a' => 'D', 'b' => 'E', 'sets_a' => 0, 'sets_b' => 3],
            ['a' => 'A', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'B', 'b' => 'E', 'sets_a' => 2, 'sets_b' => 1],
            ['a' => 'C', 'b' => 'D', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'A', 'b' => 'B', 'sets_a' => 1, 'sets_b' => 2],
        ];
    }

    public function testTotauxDeLaPouleE(): void
    {
        $stats = ClassementPoule::statistiques(['A', 'B', 'C', 'D', 'E'], self::pouleE());

        // Valeurs lues sur la feuille : Vict, Def, Sets +, Sets -
        $attendu = [
            'A' => [3, 1, 10, 2],
            'B' => [3, 1, 8, 4],
            'C' => [3, 1, 7, 5],
            'D' => [0, 4, 0, 12],
            'E' => [1, 3, 5, 7],
        ];

        foreach ($attendu as $joueur => [$v, $d, $sp, $sc]) {
            self::assertSame($v, $stats[$joueur]['victoires'], "victoires de $joueur");
            self::assertSame($d, $stats[$joueur]['defaites'], "defaites de $joueur");
            self::assertSame($sp, $stats[$joueur]['sets_pour'], "sets gagnes par $joueur");
            self::assertSame($sc, $stats[$joueur]['sets_contre'], "sets perdus par $joueur");
        }
    }

    public function testClassementDeLaPouleE(): void
    {
        $classement = ClassementPoule::classer(['A', 'B', 'C', 'D', 'E'], self::pouleE(), FormatMatch::TroisSetsSecs);

        self::assertSame(
            ['A', 'B', 'C', 'E', 'D'],
            array_column($classement, 'joueur')
        );

        self::assertSame([1, 2, 3, 4, 5], array_column($classement, 'place'));
        self::assertSame([false, false, false, false, false], array_column($classement, 'ex_aequo'));
    }

    /**
     * Le point central : a trois joueurs a egalite, c'est le
     * sous-championnat qui tranche, pas la difference de sets sur
     * l'ensemble de la poule.
     */
    public function testDepartageParSousChampionnat(): void
    {
        // A bat B, B bat C, C bat A : cycle parfait, aucune confrontation
        // directe ne suffit. D perd tout, mais A, B et C ont des scores
        // ecrases contre lui, ce qui fausserait un departage global.
        $matchs = [
            ['a' => 'A', 'b' => 'B', 'sets_a' => 3, 'sets_b' => 1],
            ['a' => 'B', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'C', 'b' => 'A', 'sets_a' => 3, 'sets_b' => 2],
            ['a' => 'A', 'b' => 'D', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'B', 'b' => 'D', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'C', 'b' => 'D', 'sets_a' => 3, 'sets_b' => 0],
        ];

        $classement = ClassementPoule::classer(['A', 'B', 'C', 'D'], $matchs, FormatMatch::TroisSetsGagnants);

        // Sous-championnat : A +1, B +1, C -2. A et B restent a egalite,
        // leur confrontation directe (A bat B) les separe.
        self::assertSame(['A', 'B', 'C', 'D'], array_column($classement, 'joueur'));
        self::assertSame('D', $classement[3]['joueur']);
    }

    public function testConfrontationDirecteADeuxJoueurs(): void
    {
        $matchs = [
            ['a' => 'A', 'b' => 'B', 'sets_a' => 1, 'sets_b' => 3],
            ['a' => 'A', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'B', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 2],
        ];

        // A et B ont chacun une victoire ; B a battu A.
        $classement = ClassementPoule::classer(['A', 'B', 'C'], $matchs, FormatMatch::TroisSetsGagnants);

        self::assertSame(['B', 'A', 'C'], array_column($classement, 'joueur'));
    }

    public function testHierarchieDansLeSousGroupe(): void
    {
        // D gagne tout. A, B et C forment une hierarchie entre eux :
        // A bat B et C, B bat C. Les victoires du sous-championnat
        // suffisent, sans recourir aux sets.
        $matchs = [
            ['a' => 'D', 'b' => 'A', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'D', 'b' => 'B', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'D', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'A', 'b' => 'B', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'A', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'B', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 0],
        ];

        self::assertSame(
            ['D', 'A', 'B', 'C'],
            array_column(ClassementPoule::classer(['A', 'B', 'C', 'D'], $matchs, FormatMatch::TroisSetsGagnants), 'joueur')
        );
    }

    public function testEgaliteIrreductible(): void
    {
        // Cycle parfait, tous les scores identiques : aucun critere ne
        // separe. L'organisateur devra trancher.
        $matchs = [
            ['a' => 'A', 'b' => 'B', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'B', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'C', 'b' => 'A', 'sets_a' => 3, 'sets_b' => 0],
        ];

        $classement = ClassementPoule::classer(['A', 'B', 'C'], $matchs, FormatMatch::TroisSetsGagnants);

        self::assertSame([true, true, true], array_column($classement, 'ex_aequo'));
        self::assertSame([1, 2, 3], array_column($classement, 'place'));
    }

    public function testLesPointsDeSetDepartagentLeCyclePartait(): void
    {
        // Meme cycle, mais les points de chaque set sont renseignes.
        $matchs = [
            ['a' => 'A', 'b' => 'B', 'sets_a' => 3, 'sets_b' => 0, 'points_a' => 33, 'points_b' => 20],
            ['a' => 'B', 'b' => 'C', 'sets_a' => 3, 'sets_b' => 0, 'points_a' => 33, 'points_b' => 25],
            ['a' => 'C', 'b' => 'A', 'sets_a' => 3, 'sets_b' => 0, 'points_a' => 33, 'points_b' => 30],
        ];

        $classement = ClassementPoule::classer(['A', 'B', 'C'], $matchs, FormatMatch::TroisSetsGagnants);

        self::assertSame([false, false, false], array_column($classement, 'ex_aequo'));
        self::assertSame(['A', 'B', 'C'], array_column($classement, 'joueur'));
    }

    public function testPouleIncomplete(): void
    {
        // Tous les matchs ne sont pas encore joues : le classement doit
        // rester coherent avec ce qui est encode.
        $matchs = [
            ['a' => 'A', 'b' => 'B', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'C', 'b' => 'D', 'sets_a' => 3, 'sets_b' => 1],
        ];

        $classement = ClassementPoule::classer(['A', 'B', 'C', 'D'], $matchs, FormatMatch::TroisSetsGagnants);

        self::assertCount(4, $classement);
        self::assertSame(1, $classement[0]['victoires']);
        self::assertSame(0, $classement[3]['victoires']);
    }

    public function testPouleVide(): void
    {
        $classement = ClassementPoule::classer(['A', 'B', 'C'], [], FormatMatch::TroisSetsSecs);

        self::assertCount(3, $classement);
        self::assertSame([true, true, true], array_column($classement, 'ex_aequo'));
    }

    public function testPlacesToujoursDistinctesEtCompletes(): void
    {
        $classement = ClassementPoule::classer(
            ['A', 'B', 'C', 'D', 'E'],
            self::pouleE(),
            FormatMatch::TroisSetsSecs
        );
        $places = array_column($classement, 'place');

        self::assertSame(range(1, 5), $places);
        self::assertSame(count($places), count(array_unique($places)));
    }

    /**
     * LE TEST CENTRAL : les criteres dependent du format.
     *
     * Cas releve par l'organisateur. Van Aubel a remporte un match, pas
     * Parent, mais son bilan en sets est moins bon : 2 sets gagnes pour
     * -5 de difference, contre 3 sets gagnes pour -3.
     *
     * En trois sets secs, tous les matchs vont au bout : les victoires
     * ne comptent pas, Parent passe devant.
     *
     * En sets gagnants, un match s'arrete des qu'il est gagne : la
     * victoire reprend son sens et Van Aubel repasse devant.
     */
    public function testLesCriteresDependentDuFormat(): void
    {
        $matchs = [
            ['a' => 'VanAubel', 'b' => 'Parent', 'sets_a' => 2, 'sets_b' => 1],
            ['a' => 'X', 'b' => 'VanAubel', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'Y', 'b' => 'VanAubel', 'sets_a' => 3, 'sets_b' => 0],
            ['a' => 'X', 'b' => 'Parent', 'sets_a' => 2, 'sets_b' => 1],
            ['a' => 'Y', 'b' => 'Parent', 'sets_a' => 2, 'sets_b' => 1],
            ['a' => 'X', 'b' => 'Y', 'sets_a' => 2, 'sets_b' => 1],
        ];

        $joueurs = ['VanAubel', 'Parent', 'X', 'Y'];

        self::assertSame(
            ['X', 'Y', 'Parent', 'VanAubel'],
            array_column(
                ClassementPoule::classer($joueurs, $matchs, FormatMatch::TroisSetsSecs),
                'joueur'
            ),
            'En trois sets secs, le bilan en sets prime sur les victoires.'
        );

        self::assertSame(
            ['X', 'Y', 'VanAubel', 'Parent'],
            array_column(
                ClassementPoule::classer($joueurs, $matchs, FormatMatch::TroisSetsGagnants),
                'joueur'
            ),
            'En sets gagnants, les victoires viennent en tete.'
        );
    }

    /**
     * En trois sets secs et poule terminee, « sets gagnes » et
     * « difference de sets » sont equivalents : chaque joueur disputant
     * 3 x (n-1) sets, la difference vaut 2 x sets gagnes - 3 x (n-1).
     *
     * Le second critere ne departage donc que les poules inachevees.
     */
    public function testSetsEtDifferenceSontLiesEnTroisSetsSecs(): void
    {
        $classement = ClassementPoule::classer(['A', 'B', 'C', 'D', 'E'], self::pouleE(), FormatMatch::TroisSetsSecs);

        foreach ($classement as $l) {
            self::assertSame(
                2 * $l['sets_pour'] - 3 * 4,
                $l['diff'],
                'La difference se deduit des sets gagnes'
            );
        }
    }

    /**
     * En trois sets secs, la difference globale est comparable parce que
     * chaque match compte exactement trois sets. Ce test le verifie sur
     * la poule E : chaque joueur y a dispute quatre matchs, donc douze
     * sets au total.
     */
    public function testEnTroisSetsSecsChaqueJoueurDisputeLeMemeNombreDeSets(): void
    {
        $stats = ClassementPoule::statistiques(['A', 'B', 'C', 'D', 'E'], self::pouleE());

        foreach ($stats as $joueur => $s) {
            self::assertSame(
                12,
                $s['sets_pour'] + $s['sets_contre'],
                "Le joueur $joueur devrait avoir dispute 12 sets"
            );
        }
    }

}
