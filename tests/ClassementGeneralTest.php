<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Domain\ClassementGeneral;
use RMCF\Tournois\Domain\FormatMatch;

final class ClassementGeneralTest extends TestCase
{
    /**
     * Six joueurs repartis en trois poules.
     *
     * @return list<array{id:int,place_poule:?int,victoires:int,sets_pour:int,sets_contre:int}>
     */
    private static function participants(): array
    {
        return [
            ['id' => 11, 'place_poule' => 1, 'victoires' => 4, 'sets_pour' => 12, 'sets_contre' => 2],
            ['id' => 21, 'place_poule' => 1, 'victoires' => 4, 'sets_pour' => 12, 'sets_contre' => 3],
            ['id' => 31, 'place_poule' => 1, 'victoires' => 3, 'sets_pour' => 11, 'sets_contre' => 4],
            ['id' => 12, 'place_poule' => 2, 'victoires' => 4, 'sets_pour' => 13, 'sets_contre' => 3],
            ['id' => 22, 'place_poule' => 2, 'victoires' => 3, 'sets_pour' => 10, 'sets_contre' => 5],
            ['id' => 13, 'place_poule' => 3, 'victoires' => 2, 'sets_pour' => 8, 'sets_contre' => 7],
        ];
    }

    /**
     * Le critere premier est la place en poule, et il prime sur tout le
     * reste : le joueur 12, deuxieme de sa poule avec quatre victoires,
     * reste derriere le joueur 31, premier de la sienne avec seulement
     * trois victoires.
     */
    public function testLaPlaceEnPoulePrime(): void
    {
        $classement = ClassementGeneral::classer(self::participants(), FormatMatch::TroisSetsSecs);

        self::assertSame(
            [11, 21, 31, 12, 22, 13],
            array_column($classement, 'id')
        );
    }

    public function testOrdreDesCriteresSecondaires(): void
    {
        // Deux premiers de poule a quatre victoires : 12 sets gagnes
        // chacun, mais 11 a une meilleure difference.
        $classement = ClassementGeneral::classer(self::participants(), FormatMatch::TroisSetsSecs);

        self::assertSame(11, $classement[0]['id']);
        self::assertSame(21, $classement[1]['id']);
        self::assertFalse($classement[0]['ex_aequo']);
    }

    /**
     * L'ordre publie est « sets gagnes » AVANT « difference de sets ».
     * Ce test le fige : un joueur qui gagne plus de sets passe devant,
     * meme si sa difference est moins bonne.
     */
    public function testSetsGagnesAvantDifferenceDeSets(): void
    {
        $participants = [
            // Meme place, memes victoires. A gagne plus de sets,
            // B a une meilleure difference.
            ['id' => 1, 'place_poule' => 1, 'victoires' => 3, 'sets_pour' => 10, 'sets_contre' => 6],
            ['id' => 2, 'place_poule' => 1, 'victoires' => 3, 'sets_pour' => 9, 'sets_contre' => 3],
        ];

        $classement = ClassementGeneral::classer($participants, FormatMatch::TroisSetsSecs);

        self::assertSame(1, $classement[0]['id'], 'Les sets gagnes priment sur la difference');
        self::assertSame(4, $classement[0]['diff']);
        self::assertSame(6, $classement[1]['diff']);
    }

    /**
     * En sets gagnants, les criteres de sets ne sont pas applicables :
     * le classement s'arrete apres les victoires et signale l'egalite.
     */
    public function testEnSetsGagnantsLesEgalitesSontSignalees(): void
    {
        $classement = ClassementGeneral::classer(self::participants(), FormatMatch::TroisSetsGagnants);

        // 11 et 21 : meme place de poule, memes victoires.
        self::assertTrue($classement[0]['ex_aequo']);
        self::assertTrue($classement[1]['ex_aequo']);

        // 31 est seul premier de poule a trois victoires.
        self::assertSame(31, $classement[2]['id']);
        self::assertFalse($classement[2]['ex_aequo']);

        self::assertTrue(ClassementGeneral::comporteDesEgalites($classement));
    }

    public function testEnTroisSetsSecsAucuneEgaliteIci(): void
    {
        $classement = ClassementGeneral::classer(self::participants(), FormatMatch::TroisSetsSecs);

        self::assertFalse(ClassementGeneral::comporteDesEgalites($classement));
    }

    public function testPlacesConsecutivesEtDistinctes(): void
    {
        foreach (FormatMatch::tous() as $format) {
            $places = array_column(
                ClassementGeneral::classer(self::participants(), $format),
                'place'
            );

            self::assertSame(range(1, 6), $places, $format->value);
        }
    }

    /**
     * Une place de poule non calculee — poule inachevee — ne doit pas
     * remonter le joueur en tete du classement.
     */
    public function testPlaceDePouleAbsente(): void
    {
        $participants = [
            ['id' => 1, 'place_poule' => null, 'victoires' => 5, 'sets_pour' => 15, 'sets_contre' => 0],
            ['id' => 2, 'place_poule' => 1, 'victoires' => 1, 'sets_pour' => 3, 'sets_contre' => 9],
        ];

        $classement = ClassementGeneral::classer($participants, FormatMatch::TroisSetsSecs);

        self::assertSame(2, $classement[0]['id'], 'Une place absente passe en dernier');
    }

    public function testListeVide(): void
    {
        self::assertSame([], ClassementGeneral::classer([], FormatMatch::TroisSetsSecs));
        self::assertFalse(ClassementGeneral::comporteDesEgalites([]));
    }

    public function testUnSeulParticipant(): void
    {
        $classement = ClassementGeneral::classer(
            [['id' => 7, 'place_poule' => 1, 'victoires' => 0, 'sets_pour' => 0, 'sets_contre' => 0]],
            FormatMatch::TroisSetsSecs
        );

        self::assertCount(1, $classement);
        self::assertSame(1, $classement[0]['place']);
        self::assertFalse($classement[0]['ex_aequo']);
    }

    /**
     * Tous les premiers de poule precedent tous les deuxiemes, quels que
     * soient leurs autres totaux. C'est la propriete structurante du
     * classement general.
     */
    public function testTousLesPremiersAvantTousLesDeuxiemes(): void
    {
        $classement = ClassementGeneral::classer(self::participants(), FormatMatch::TroisSetsSecs);

        $dernierPremier = null;
        $premierSecond  = null;

        foreach ($classement as $i => $ligne) {
            if ($ligne['place_poule'] === 1) {
                $dernierPremier = $i;
            } elseif ($ligne['place_poule'] === 2 && $premierSecond === null) {
                $premierSecond = $i;
            }
        }

        self::assertNotNull($dernierPremier);
        self::assertNotNull($premierSecond);
        self::assertLessThan($premierSecond, $dernierPremier);
    }
}
