<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\OrdreMatchs;

/**
 * Ces tests verifient les proprietes attendues des sequences : chaque
 * paire se rencontre une fois et une seule, personne n'enchaine deux
 * matchs, l'arbitre ne joue pas le match qu'il arbitre, et la charge
 * d'arbitrage est repartie.
 */
final class OrdreMatchsTest extends TestCase
{
    /** @return list<array{int}> */
    public static function tailles(): array
    {
        return array_map(static fn (int $n): array => [$n], OrdreMatchs::taillesConnues());
    }

    /** @dataProvider tailles */
    public function testNombreDeMatchs(int $taille): void
    {
        self::assertCount(
            OrdreMatchs::nombreMatchs($taille),
            OrdreMatchs::pour($taille),
            "Une poule de $taille doit compter n(n-1)/2 matchs"
        );
    }

    /** @dataProvider tailles */
    public function testChaquePaireUneSeuleFois(int $taille): void
    {
        $vues = [];

        foreach (OrdreMatchs::pour($taille) as [$a, $b]) {
            $paire = $a < $b ? "$a-$b" : "$b-$a";
            self::assertArrayNotHasKey($paire, $vues, "La paire $paire revient deux fois");
            $vues[$paire] = true;
        }

        self::assertCount(OrdreMatchs::nombreMatchs($taille), $vues);
    }

    /** @dataProvider tailles */
    public function testTousLesJoueursSontDansLaPoule(int $taille): void
    {
        $max = chr(ord('A') + $taille - 1);

        foreach (OrdreMatchs::pour($taille) as [$a, $b, $arbitre]) {
            foreach ([$a, $b, $arbitre] as $lettre) {
                self::assertLessThanOrEqual($max, $lettre, "$lettre depasse la taille de la poule");
                self::assertGreaterThanOrEqual('A', $lettre);
            }
        }
    }

    /** @dataProvider tailles */
    public function testChaqueJoueurDisputeLeBonNombreDeMatchs(int $taille): void
    {
        $compte = [];

        foreach (OrdreMatchs::pour($taille) as [$a, $b]) {
            $compte[$a] = ($compte[$a] ?? 0) + 1;
            $compte[$b] = ($compte[$b] ?? 0) + 1;
        }

        self::assertCount($taille, $compte);

        foreach ($compte as $lettre => $n) {
            self::assertSame($taille - 1, $n, "Le joueur $lettre ne dispute pas " . ($taille - 1) . ' matchs');
        }
    }

    /**
     * Des 5 joueurs, personne ne doit enchainer deux matchs de suite.
     * En poule de 3 et de 4, c'est mathematiquement impossible : la
     * borne est de deux enchainements, et les sequences l'atteignent.
     *
     * @dataProvider tailles
     */
    public function testPersonneNeJoueDeuxMatchsDeSuite(int $taille): void
    {
        $dernier       = [];
        $enchainements = 0;

        foreach (OrdreMatchs::pour($taille) as $i => [$a, $b]) {
            foreach ([$a, $b] as $j) {
                if (isset($dernier[$j]) && $i - $dernier[$j] === 1) {
                    $enchainements++;
                }
                $dernier[$j] = $i;
            }
        }

        $borne = $taille <= 4 ? 2 : 0;

        self::assertLessThanOrEqual(
            $borne,
            $enchainements,
            "Poule de $taille : $enchainements enchainement(s), borne $borne"
        );
    }

    /**
     * L'arbitre ne peut jamais disputer le match qu'il arbitre.
     *
     * @dataProvider tailles
     */
    public function testArbitreNeJouePasSonMatch(int $taille): void
    {
        foreach (OrdreMatchs::pour($taille) as [$a, $b, $arbitre]) {
            self::assertNotSame($a, $arbitre, "Match $a-$b : l'arbitre dispute le match");
            self::assertNotSame($b, $arbitre, "Match $a-$b : l'arbitre dispute le match");
        }
    }

    /**
     * Repartition de l'arbitrage.
     *
     * La convention federale n'est pas optimale sur ce point : en poule
     * de 8, un joueur arbitre cinq fois quand un autre ne le fait que
     * deux. Le test fige cet ecart plutot que d'exiger l'optimum, de
     * facon a detecter une alteration des sequences sans pretendre les
     * corriger.
     *
     * @dataProvider tailles
     */
    public function testRepartitionDeLArbitrage(int $taille): void
    {
        $compte = array_fill_keys(
            array_map(static fn (int $i): string => chr(ord('A') + $i), range(0, $taille - 1)),
            0
        );

        foreach (OrdreMatchs::pour($taille) as [, , $arbitre]) {
            $compte[$arbitre]++;
        }

        // Ecarts constates sur les sequences federales.
        $ecartMaximal = [3 => 0, 4 => 1, 5 => 0, 6 => 1, 7 => 0, 8 => 3];

        self::assertSame(
            $ecartMaximal[$taille],
            max($compte) - min($compte),
            "Poule de $taille : repartition modifiee (" . implode(', ', $compte) . ')'
        );

        self::assertSame(
            OrdreMatchs::nombreMatchs($taille),
            array_sum($compte),
            'Chaque match doit avoir exactement un arbitre'
        );
    }

    /**
     * Fige la correction apportee a la convention : le seizieme match de
     * la poule de 8 designait B, qui disputait ce match.
     */
    public function testArbitreDuSeiziemeMatchEnPouleDeHuit(): void
    {
        self::assertSame(['B', 'H', 'F'], OrdreMatchs::pour(8)[15]);
    }

    /**
     * La sequence de la poule de 5, relevee sur la feuille POULE E de
     * MbN4.xlsm.
     */
    public function testSequenceDeLaPouleDeCinq(): void
    {
        $attendu = ['A-E', 'B-D', 'C-E', 'A-D', 'B-C', 'D-E', 'A-C', 'B-E', 'C-D', 'A-B'];

        $obtenu = array_map(
            static fn (array $m): string => $m[0] . '-' . $m[1],
            OrdreMatchs::pour(5)
        );

        self::assertSame($attendu, $obtenu);
    }

    public function testCouvertureDesTailles(): void
    {
        self::assertSame(range(3, 8), OrdreMatchs::taillesConnues());
    }

    public function testTailleInconnue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OrdreMatchs::pour(9);
    }
}
