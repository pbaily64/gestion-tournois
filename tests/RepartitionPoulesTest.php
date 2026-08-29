<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\RepartitionPoules;
use RMCF\Tournois\Domain\Serpentin;

final class RepartitionPoulesTest extends TestCase
{
    public function testDixHuitJoueurs(): void
    {
        // Cas cite par l'organisateur : 3 poules de 6 ou 6 poules de 3.
        $options = RepartitionPoules::options(18);
        $parNb   = array_column($options, null, 'nb_poules');

        self::assertArrayHasKey(3, $parNb);
        self::assertSame('3 poules de 6', $parNb[3]['composition']);
        self::assertSame(45, $parNb[3]['nb_matchs']);

        self::assertArrayHasKey(6, $parNb);
        self::assertSame('6 poules de 3', $parNb[6]['composition']);
        self::assertSame(18, $parNb[6]['nb_matchs']);
    }

    public function testVingtSixJoueurs(): void
    {
        $parNb = array_column(RepartitionPoules::options(26), null, 'nb_poules');

        self::assertSame([4, 5, 6, 7, 8], array_keys($parNb));
        self::assertSame(72, $parNb[4]['nb_matchs']);
        self::assertSame(30, $parNb[8]['nb_matchs']);
        self::assertSame('2 poules de 7 + 2 poules de 6', $parNb[4]['composition']);
    }

    public function testTaillesEquilibrees(): void
    {
        foreach (range(6, 64) as $n) {
            foreach (RepartitionPoules::options($n) as $o) {
                $tailles = $o['tailles'];

                self::assertSame($n, array_sum($tailles), "Effectif perdu pour $n joueurs");
                self::assertCount($o['nb_poules'], $tailles);
                self::assertLessThanOrEqual(
                    1,
                    max($tailles) - min($tailles),
                    "Poules desequilibrees pour $n joueurs"
                );
            }
        }
    }

    public function testBornesRespectees(): void
    {
        foreach (range(1, 80) as $n) {
            foreach (RepartitionPoules::options($n) as $o) {
                self::assertGreaterThanOrEqual(Serpentin::JOUEURS_PAR_POULE_MIN, min($o['tailles']));
                self::assertLessThanOrEqual(Serpentin::JOUEURS_PAR_POULE_MAX, max($o['tailles']));
                self::assertGreaterThanOrEqual(Serpentin::POULES_MIN, $o['nb_poules']);
                self::assertLessThanOrEqual(Serpentin::POULES_MAX, $o['nb_poules']);
            }
        }
    }

    public function testMoinsDePoulesDonnePlusDeMatchs(): void
    {
        foreach ([18, 26, 30, 42] as $n) {
            $options = RepartitionPoules::options($n);
            $precedent = PHP_INT_MAX;

            foreach ($options as $o) {
                self::assertLessThan(
                    $precedent,
                    $o['nb_matchs'],
                    "Le nombre de matchs devrait decroitre quand les poules augmentent ($n joueurs)"
                );
                $precedent = $o['nb_matchs'];
            }
        }
    }

    public function testCoherenceAvecLeSerpentin(): void
    {
        // Les tailles annoncees doivent correspondre a ce que le
        // serpentin produit reellement.
        foreach ([18, 26, 42, 37] as $n) {
            foreach (RepartitionPoules::options($n) as $o) {
                $poules  = Serpentin::repartir(range(1, $n), $o['nb_poules']);
                $reelles = array_map('count', $poules);

                sort($reelles);
                $attendues = $o['tailles'];
                sort($attendues);

                self::assertSame($attendues, $reelles, "$n joueurs en {$o['nb_poules']} poules");
            }
        }
    }

    public function testEffectifTropPetit(): void
    {
        // 5 joueurs : 2 poules donneraient 3 et 2, sous le minimum de 3.
        self::assertSame([], RepartitionPoules::options(5));
        self::assertFalse(RepartitionPoules::estPossible(5, 2));
    }

    public function testEffectifTropGrand(): void
    {
        // 8 poules de 8 au maximum, soit 64 joueurs.
        self::assertNotSame([], RepartitionPoules::options(64));
        self::assertSame([], RepartitionPoules::options(65));
    }

    public function testNombreDeMatchs(): void
    {
        self::assertSame(3, RepartitionPoules::nombreMatchs([3]));
        self::assertSame(28, RepartitionPoules::nombreMatchs([8]));

        // 6 + 6 + 3 + 3
        self::assertSame(18, RepartitionPoules::nombreMatchs([4, 4, 3, 3]));

        // 15 + 15 + 10 + 10
        self::assertSame(50, RepartitionPoules::nombreMatchs([6, 6, 5, 5]));

        self::assertSame(0, RepartitionPoules::nombreMatchs([]));
    }

    public function testComposition(): void
    {
        self::assertSame('1 poule de 6 + 4 poules de 5', RepartitionPoules::composition([6, 5, 5, 5, 5]));
        self::assertSame('3 poules de 6', RepartitionPoules::composition([6, 6, 6]));
    }
}
