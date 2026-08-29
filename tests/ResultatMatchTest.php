<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\FormatMatch;
use RMCF\Tournois\Domain\ResultatInvalide;
use RMCF\Tournois\Domain\ResultatMatch;

final class ResultatMatchTest extends TestCase
{
    // -----------------------------------------------------------------
    //  Validation d'un set isole
    // -----------------------------------------------------------------

    public function testSetsValides(): void
    {
        foreach ([[11, 0], [11, 9], [12, 10], [13, 11], [15, 13], [9, 11], [0, 11]] as [$a, $b]) {
            self::assertNull(ResultatMatch::verifierManche($a, $b), "$a-$b devrait etre accepte");
        }
    }

    public function testSetsInvalides(): void
    {
        // 11-10 : il faut deux points d'ecart
        // 10-8  : le set n'est pas alle au bout
        // 12-9  : au-dela de 11, l'ecart doit valoir exactement deux
        foreach ([[11, 10], [10, 8], [12, 9], [14, 11], [5, 3], [0, 0]] as [$a, $b]) {
            self::assertNotNull(ResultatMatch::verifierManche($a, $b), "$a-$b devrait etre refuse");
        }
    }

    public function testScoreNegatif(): void
    {
        self::assertNotNull(ResultatMatch::verifierManche(-1, 11));
    }

    // -----------------------------------------------------------------
    //  Deduction du resultat
    // -----------------------------------------------------------------

    public function testTroisSetsSecs(): void
    {
        $r = ResultatMatch::depuisCases(
            [[11, 7], [9, 11], [11, 5]],
            FormatMatch::TroisSetsSecs
        );

        self::assertSame(2, $r->sets1);
        self::assertSame(1, $r->sets2);
        self::assertTrue($r->vainqueurEstLePremier());
        self::assertSame('1', $r->vainqueur());
        self::assertCount(3, $r->manches);
    }

    public function testTroisSetsSecsExigeLesTroisSets(): void
    {
        $this->expectException(ResultatInvalide::class);

        ResultatMatch::depuisCases(
            [[11, 7], [11, 5], [null, null]],
            FormatMatch::TroisSetsSecs
        );
    }

    public function testTroisSetsGagnantsEnQuatreSets(): void
    {
        $r = ResultatMatch::depuisCases(
            [[11, 7], [9, 11], [11, 5], [12, 10], [null, null]],
            FormatMatch::TroisSetsGagnants
        );

        self::assertSame(3, $r->sets1);
        self::assertSame(1, $r->sets2);
        self::assertSame('1', $r->vainqueur());
    }

    public function testTroisSetsGagnantsEnCinqSets(): void
    {
        $r = ResultatMatch::depuisCases(
            [[11, 7], [9, 11], [11, 5], [8, 11], [13, 15]],
            FormatMatch::TroisSetsGagnants
        );

        self::assertSame(2, $r->sets1);
        self::assertSame(3, $r->sets2);
        self::assertSame('2', $r->vainqueur());
    }

    public function testDeuxSetsGagnants(): void
    {
        $r = ResultatMatch::depuisCases(
            [[11, 4], [11, 9], [null, null]],
            FormatMatch::DeuxSetsGagnants
        );

        self::assertSame(2, $r->sets1);
        self::assertSame(0, $r->sets2);
    }

    /**
     * En sets gagnants, on ne dispute pas de set apres la victoire.
     */
    public function testAucunSetApresLaVictoire(): void
    {
        $this->expectException(ResultatInvalide::class);

        ResultatMatch::depuisCases(
            [[11, 7], [11, 5], [11, 3], [11, 2], [null, null]],
            FormatMatch::TroisSetsGagnants
        );
    }

    public function testTrouDansLaSaisie(): void
    {
        $this->expectException(ResultatInvalide::class);

        ResultatMatch::depuisCases(
            [[11, 7], [null, null], [11, 5]],
            FormatMatch::TroisSetsSecs
        );
    }

    public function testUnSeulScoreDansUnSet(): void
    {
        $this->expectException(ResultatInvalide::class);

        ResultatMatch::depuisCases(
            [[11, null], [11, 5], [11, 3]],
            FormatMatch::TroisSetsSecs
        );
    }

    public function testAucunSetRenseigne(): void
    {
        $this->expectException(ResultatInvalide::class);

        ResultatMatch::depuisCases(
            [[null, null], [null, null], [null, null]],
            FormatMatch::TroisSetsSecs
        );
    }

    public function testSetIrregulierRejete(): void
    {
        $this->expectException(ResultatInvalide::class);

        ResultatMatch::depuisCases(
            [[11, 7], [11, 10], [11, 5]],
            FormatMatch::TroisSetsSecs
        );
    }

    /**
     * Le handicap est deja compris dans les points encodes : le score du
     * marquoir fait foi, aucune correction n'est appliquee ici.
     */
    public function testHandicapCompris(): void
    {
        // Le joueur 2 demarre a 6 points et l'emporte 11-8 : il n'a
        // marque que 5 points reels, mais 8-11 est bien ce qui est
        // affiche et ce qui doit etre enregistre.
        $r = ResultatMatch::depuisCases(
            [[8, 11], [6, 11], [9, 11]],
            FormatMatch::TroisSetsSecs
        );

        self::assertSame(0, $r->sets1);
        self::assertSame(3, $r->sets2);
        self::assertSame([[8, 11], [6, 11], [9, 11]], $r->manches);
    }

    public function testLesManchesVidesNeSontPasConservees(): void
    {
        $r = ResultatMatch::depuisCases(
            [[11, 7], [11, 5], [null, null], [null, null], [null, null]],
            FormatMatch::DeuxSetsGagnants
        );

        self::assertCount(2, $r->manches);
    }

    public function testChaineVideEquivautAUneCaseVide(): void
    {
        // Un formulaire HTML renvoie des chaines vides, pas des null.
        $r = ResultatMatch::depuisCases(
            [[11, 7], [11, 5], ['', '']],
            FormatMatch::DeuxSetsGagnants
        );

        self::assertSame(2, $r->sets1);
        self::assertCount(2, $r->manches);
    }
}
