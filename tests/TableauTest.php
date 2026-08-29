<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\Tableau;

final class TableauTest extends TestCase
{
    /**
     * Ordre repris de la colonne A de la feuille Tab_Final.
     */
    public function testPlacementDuTableauFinal(): void
    {
        self::assertSame(
            [1, 16, 9, 8, 5, 12, 13, 4, 3, 14, 11, 6, 7, 10, 15, 2],
            Tableau::placement()
        );
    }

    /**
     * La consolante suit le meme ordre, decale de seize.
     */
    public function testPlacementDeLaConsolante(): void
    {
        self::assertSame(
            [17, 32, 25, 24, 21, 28, 29, 20, 19, 30, 27, 22, 23, 26, 31, 18],
            Tableau::placement(16)
        );
    }

    public function testChaquePlaceApparaitUneFois(): void
    {
        $ordre = Tableau::placement();

        sort($ordre);

        self::assertSame(range(1, 16), $ordre);
    }

    /**
     * La premiere tete de serie rencontre la derniere, la huitieme la
     * neuvieme : les mieux classes ne se croisent qu'au plus tard.
     */
    public function testPremierTour(): void
    {
        $matchs = Tableau::premierTour();

        self::assertCount(8, $matchs);
        self::assertSame([1, 16], $matchs[0]);
        self::assertSame([9, 8], $matchs[1]);

        foreach ($matchs as [$a, $b]) {
            self::assertSame(17, $a + $b, "$a contre $b : la somme doit valoir 17");
        }
    }

    public function testNombreDeMatchsParTour(): void
    {
        self::assertSame(8, Tableau::nombreDeMatchs('8e'));
        self::assertSame(4, Tableau::nombreDeMatchs('quart'));
        self::assertSame(2, Tableau::nombreDeMatchs('demie'));
        self::assertSame(1, Tableau::nombreDeMatchs('finale'));
    }

    public function testEnchainementDesTours(): void
    {
        self::assertSame('quart', Tableau::tourSuivant('8e'));
        self::assertSame('demie', Tableau::tourSuivant('quart'));
        self::assertSame('finale', Tableau::tourSuivant('demie'));
        self::assertNull(Tableau::tourSuivant('finale'));
    }

    /**
     * Les matchs 1 et 2 d'un tour alimentent le match 1 du suivant,
     * les matchs 3 et 4 le match 2, et ainsi de suite.
     */
    public function testProgression(): void
    {
        self::assertSame(['tour' => 'quart', 'match' => 1, 'cote' => 1], Tableau::destination('8e', 1));
        self::assertSame(['tour' => 'quart', 'match' => 1, 'cote' => 2], Tableau::destination('8e', 2));
        self::assertSame(['tour' => 'quart', 'match' => 2, 'cote' => 1], Tableau::destination('8e', 3));
        self::assertSame(['tour' => 'quart', 'match' => 4, 'cote' => 2], Tableau::destination('8e', 8));
        self::assertNull(Tableau::destination('finale', 1));
    }

    /**
     * Chaque match d'un tour recoit exactement deux alimentations du
     * tour precedent.
     */
    public function testChaqueMatchEstAlimenteParDeuxMatchs(): void
    {
        foreach (['8e', 'quart', 'demie'] as $tour) {
            $recus = [];

            for ($i = 1; $i <= Tableau::nombreDeMatchs($tour); $i++) {
                $d = Tableau::destination($tour, $i);
                $recus[$d['match']][] = $d['cote'];
            }

            self::assertCount(Tableau::nombreDeMatchs(Tableau::tourSuivant($tour)), $recus, $tour);

            foreach ($recus as $cotes) {
                sort($cotes);
                self::assertSame([1, 2], $cotes, $tour);
            }
        }
    }

    public function testTourDeDepart(): void
    {
        self::assertSame('8e', Tableau::tourDeDepart(16));
        self::assertSame('8e', Tableau::tourDeDepart(9));
        self::assertSame('quart', Tableau::tourDeDepart(8));
        self::assertSame('demie', Tableau::tourDeDepart(4));
        self::assertSame('finale', Tableau::tourDeDepart(2));
    }

    public function testTourInconnu(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Tableau::nombreDeMatchs('seizieme');
    }
}
