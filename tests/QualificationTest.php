<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\Qualification;
use RMCF\Tournois\Domain\Tableau;

final class QualificationTest extends TestCase
{
    /**
     * Cas verifie sur la phase 4 du classeur : 38 joueurs poursuivant,
     * avec consolation. Excedent de 6, donc 12 joueurs en barrage
     * (places 27 a 38), 6 matchs, 26 qualifies directs.
     */
    public function testPhaseQuatreDuClasseur(): void
    {
        $q = Qualification::pour(38, true);

        self::assertSame(32, $q->cible);
        self::assertSame(6, $q->excedent);
        self::assertCount(6, $q->barrages);
        self::assertCount(26, $q->qualifiesDirects());
        self::assertSame(range(27, 38), $q->barragistes());
    }

    public function testAucunBarrageEnDessousDeDixSept(): void
    {
        foreach ([6, 12, 16] as $n) {
            foreach ([true, false] as $consolation) {
                $q = Qualification::pour($n, $consolation);

                self::assertFalse($q->avecBarrage(), "$n joueurs");
                self::assertSame(16, $q->cible);
                self::assertSame([], $q->barrages);
            }
        }
    }

    public function testSansConsolationLaCibleEstSeize(): void
    {
        $q = Qualification::pour(20, false);

        self::assertSame(16, $q->cible);
        self::assertSame(4, $q->excedent);
        self::assertSame(range(13, 20), $q->barragistes());
        self::assertSame('tableau_final', $q->destinationBarrage());
    }

    public function testAvecConsolationLaCibleEstTrenteDeux(): void
    {
        $q = Qualification::pour(23, true);

        self::assertSame(32, $q->cible);
        self::assertFalse($q->avecBarrage(), '23 joueurs tiennent dans les 32 places');
        self::assertSame('consolation', $q->destinationBarrage());
    }

    /**
     * L'appariement est croise : le meilleur du groupe rencontre le
     * moins bon.
     */
    public function testAppariementCroise(): void
    {
        $q = Qualification::pour(36, true);

        self::assertSame(4, $q->excedent);
        self::assertSame(
            [[29, 36], [30, 35], [31, 34], [32, 33]],
            $q->barrages
        );
    }

    public function testUnSeulMatchDeBarrage(): void
    {
        $q = Qualification::pour(33, true);

        self::assertSame([[32, 33]], $q->barrages, 'Le 32e rencontre le 33e');
    }

    /**
     * Chaque barragiste dispute exactement un match, et les places
     * remises en jeu sont bien celles du bas de la cible.
     */
    public function testChaqueBarragisteJoueUneFois(): void
    {
        foreach ([18, 20, 25, 33, 38, 40] as $n) {
            foreach ([true, false] as $consolation) {
                $q = Qualification::pour($n, $consolation);

                if (!$q->avecBarrage()) {
                    continue;
                }

                $vus = [];

                foreach ($q->barrages as [$a, $b]) {
                    $vus[] = $a;
                    $vus[] = $b;
                }

                sort($vus);

                self::assertSame($q->barragistes(), $vus, "$n joueurs, consolation " . var_export($consolation, true));
            }
        }
    }

    public function testQualifiesDirectsEtBarragistesNeSeChevauchentPas(): void
    {
        foreach (range(6, 64) as $n) {
            $q = Qualification::pour($n, true);

            self::assertSame(
                [],
                array_intersect($q->qualifiesDirects(), $q->barragistes()),
                "$n joueurs"
            );
        }
    }

    public function testEffectifNegatif(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Qualification::pour(-1, true);
    }
}
