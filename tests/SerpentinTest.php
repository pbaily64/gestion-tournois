<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\Serpentin;

/**
 * Ces tests valident la regle du S contre les sequences que le classeur
 * Excel stockait en dur dans la feuille ORDRE MATCH.
 */
final class SerpentinTest extends TestCase
{
    public function testSequenceTroisPoules(): void
    {
        $attendu = 'ABCCBAABCCBAABC';
        $obtenu  = '';

        for ($i = 0; $i < 15; $i++) {
            $obtenu .= Serpentin::libelle(Serpentin::pouleDe($i, 3));
        }

        self::assertSame($attendu, $obtenu);
    }

    public function testSequenceDeuxPoules(): void
    {
        $attendu = 'ABBAABBA';
        $obtenu  = '';

        for ($i = 0; $i < 8; $i++) {
            $obtenu .= Serpentin::libelle(Serpentin::pouleDe($i, 2));
        }

        self::assertSame($attendu, $obtenu);
    }

    public function testSequenceHuitPoules(): void
    {
        $attendu = 'ABCDEFGHHGFEDCBA';
        $obtenu  = '';

        for ($i = 0; $i < 16; $i++) {
            $obtenu .= Serpentin::libelle(Serpentin::pouleDe($i, 8));
        }

        self::assertSame($attendu, $obtenu);
    }

    public function testLeMeilleurJoueurEstEnPouleA(): void
    {
        for ($n = Serpentin::POULES_MIN; $n <= Serpentin::POULES_MAX; $n++) {
            self::assertSame(0, Serpentin::pouleDe(0, $n));
        }
    }

    public function testRepartitionEquilibree(): void
    {
        $joueurs = range(1, 42);
        $poules  = Serpentin::repartir($joueurs, 8);

        self::assertCount(8, $poules);
        self::assertSame(42, array_sum(array_map('count', $poules)));

        // Ecart maximal d'un joueur entre la plus grande et la plus petite poule
        $tailles = array_map('count', $poules);
        self::assertLessThanOrEqual(1, max($tailles) - min($tailles));
    }

    public function testAucunJoueurPerduNiDuplique(): void
    {
        $joueurs = range(1, 37);
        $poules  = Serpentin::repartir($joueurs, 5);

        $tous = array_merge(...array_values($poules));
        sort($tous);

        self::assertSame($joueurs, $tous);
    }

    public function testNombreDePoulesInvalide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Serpentin::pouleDe(0, 9);
    }

    public function testControleEffectifTropPetit(): void
    {
        // 8 joueurs en 4 poules : 2 par poule, sous le minimum de 3
        self::assertNotEmpty(Serpentin::erreurs(8, 4));
    }

    public function testControleEffectifTropGrand(): void
    {
        // 20 joueurs en 2 poules : 10 par poule, au-dessus du maximum de 8
        self::assertNotEmpty(Serpentin::erreurs(20, 2));
    }

    public function testRepartitionValide(): void
    {
        self::assertSame([], Serpentin::erreurs(42, 8));
        self::assertSame([], Serpentin::erreurs(30, 5));
    }
}
