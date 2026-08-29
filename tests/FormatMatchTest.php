<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\FormatMatch;

final class FormatMatchTest extends TestCase
{
    public function testNombreDeCases(): void
    {
        self::assertSame(3, FormatMatch::TroisSetsSecs->nombreDeCases());
        self::assertSame(3, FormatMatch::DeuxSetsGagnants->nombreDeCases());
        self::assertSame(5, FormatMatch::TroisSetsGagnants->nombreDeCases());
    }

    public function testTroisSetsSecsAccepteLesQuatreResultats(): void
    {
        foreach ([[3, 0], [2, 1], [1, 2], [0, 3]] as [$a, $b]) {
            self::assertNull(
                FormatMatch::TroisSetsSecs->verifier($a, $b),
                "$a-$b devrait etre accepte"
            );
        }
    }

    public function testTroisSetsSecsRefuseLesAutres(): void
    {
        // Un 3-1 fait quatre sets : impossible quand les trois sets se jouent.
        foreach ([[3, 1], [2, 0], [3, 2], [1, 0], [0, 0]] as [$a, $b]) {
            self::assertNotNull(
                FormatMatch::TroisSetsSecs->verifier($a, $b),
                "$a-$b devrait etre refuse"
            );
        }
    }

    public function testDeuxSetsGagnants(): void
    {
        foreach ([[2, 0], [2, 1], [1, 2], [0, 2]] as [$a, $b]) {
            self::assertNull(FormatMatch::DeuxSetsGagnants->verifier($a, $b), "$a-$b");
        }

        foreach ([[3, 0], [2, 2], [1, 1], [1, 0]] as [$a, $b]) {
            self::assertNotNull(FormatMatch::DeuxSetsGagnants->verifier($a, $b), "$a-$b");
        }
    }

    public function testTroisSetsGagnants(): void
    {
        foreach ([[3, 0], [3, 1], [3, 2], [2, 3], [1, 3], [0, 3]] as [$a, $b]) {
            self::assertNull(FormatMatch::TroisSetsGagnants->verifier($a, $b), "$a-$b");
        }

        foreach ([[4, 0], [3, 3], [2, 1], [2, 0]] as [$a, $b]) {
            self::assertNotNull(FormatMatch::TroisSetsGagnants->verifier($a, $b), "$a-$b");
        }
    }

    public function testAucuneEgalite(): void
    {
        foreach (FormatMatch::tous() as $format) {
            self::assertNotNull($format->verifier(1, 1), $format->value);
            self::assertNotNull($format->verifier(0, 0), $format->value);
        }
    }

    public function testAucunNombreNegatif(): void
    {
        foreach (FormatMatch::tous() as $format) {
            self::assertNotNull($format->verifier(-1, 3), $format->value);
        }
    }

    /**
     * Chaque resultat propose par les boutons de saisie rapide doit
     * evidemment passer la verification.
     */
    public function testLesResultatsProposesSontValides(): void
    {
        foreach (FormatMatch::tous() as $format) {
            $possibles = $format->resultatsPossibles();

            self::assertNotEmpty($possibles, $format->value);

            foreach ($possibles as [$a, $b]) {
                self::assertNull(
                    $format->verifier($a, $b),
                    sprintf('%s : %d-%d propose mais refuse', $format->value, $a, $b)
                );
            }
        }
    }

    public function testLesResultatsProposesSontSymetriques(): void
    {
        foreach (FormatMatch::tous() as $format) {
            $possibles = $format->resultatsPossibles();

            self::assertCount(
                count($possibles),
                array_unique(array_map(static fn (array $r): string => "$r[0]-$r[1]", $possibles)),
                'Aucun doublon attendu'
            );

            foreach ($possibles as [$a, $b]) {
                self::assertContains([$b, $a], $possibles, "Le miroir de $a-$b manque");
            }
        }
    }

    public function testResultatsAttendus(): void
    {
        self::assertSame(
            [[3, 0], [2, 1], [1, 2], [0, 3]],
            FormatMatch::TroisSetsSecs->resultatsPossibles()
        );

        self::assertSame(
            [[3, 0], [3, 1], [3, 2], [2, 3], [1, 3], [0, 3]],
            FormatMatch::TroisSetsGagnants->resultatsPossibles()
        );
    }

    /**
     * Les sets ne sont comparables entre joueurs qu'en trois sets secs,
     * ou chaque match compte le meme nombre de sets. C'est ce qui
     * conditionne la regle de classement general (section 4.5).
     */
    public function testComparabiliteDesSets(): void
    {
        self::assertTrue(FormatMatch::TroisSetsSecs->setsComparables());
        self::assertFalse(FormatMatch::DeuxSetsGagnants->setsComparables());
        self::assertFalse(FormatMatch::TroisSetsGagnants->setsComparables());
    }

    public function testValeursStockeesEnBase(): void
    {
        // Ces chaines sont celles de la colonne phase.format_match.
        self::assertSame('3_sets_secs', FormatMatch::TroisSetsSecs->value);
        self::assertSame('2_sets_gagnants', FormatMatch::DeuxSetsGagnants->value);
        self::assertSame('3_sets_gagnants', FormatMatch::TroisSetsGagnants->value);

        self::assertSame(FormatMatch::TroisSetsSecs, FormatMatch::from('3_sets_secs'));
    }
}
