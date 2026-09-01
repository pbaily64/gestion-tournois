<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests\Formule;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Formule\Flux\Flux;
use RMCF\Tournois\Formule\Flux\MoteurFlux;
use RMCF\Tournois\Formule\Flux\ResultatsEnMemoire;
use RMCF\Tournois\Formule\Flux\Selecteur;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * Le moteur de flux (C.5, §9.5) — le composant le plus reutilise.
 *
 * Le document le place en deuxieme position dans l'ordre de mise en
 * oeuvre (§12.3) parce qu'il debloque consolante, barrage, vies et
 * criterium d'un coup. C'est donc aussi celui dont une erreur se
 * propage le plus loin : un joueur envoye dans la mauvaise phase ne se
 * remarque qu'au moment ou il ne trouve pas son nom sur le tableau.
 */
final class MoteurFluxTest extends TestCase
{
    /** Quatre poules de quatre, classement declare, phase close. */
    private function poulesClosees(): ResultatsEnMemoire
    {
        $resultats = new ResultatsEnMemoire();

        foreach (['A', 'B', 'C', 'D'] as $poule) {
            $resultats->classer('poules', $poule, [
                '1' . $poule, '2' . $poule, '3' . $poule, '4' . $poule,
            ]);
        }

        return $resultats;
    }

    public function testPlaceExacteRemonteLesPremiersDeChaquePoule(): void
    {
        $moteur = new MoteurFlux($this->poulesClosees());

        $resolus = $moteur->resoudre(
            [new Flux('poules', 'tableau', Selecteur::PlaceExacte, 1)],
            Plateau::vide()
        );

        self::assertSame(['1A', '1B', '1C', '1D'], $resolus['tableau']->entrants->refs());
    }

    public function testPlacesDeAPrendLesDeuxPremiersDansLOrdreCroise(): void
    {
        $moteur = new MoteurFlux($this->poulesClosees());

        $resolus = $moteur->resoudre(
            [new Flux('poules', 'tableau', Selecteur::PlacesDeA, '1-2')],
            Plateau::vide()
        );

        // Tous les premiers, puis tous les deuxiemes en sens inverse :
        // c'est ce qui eloigne 1A de 2A dans le tableau (RG-34).
        self::assertSame(['1A', '1B', '1C', '1D', '2D', '2C', '2B', '2A'], $resolus['tableau']->entrants->refs());
    }

    public function testNonQualifiesEstEvalueEnDernier(): void
    {
        $moteur = new MoteurFlux($this->poulesClosees());

        // Volontairement declare AVANT le flux vers le tableau : RG-32
        // impose qu'il soit malgre tout evalue en dernier.
        $resolus = $moteur->resoudre(
            [
                new Flux('poules', 'consolante', Selecteur::NonQualifies, null, 1),
                new Flux('poules', 'tableau', Selecteur::PlacesDeA, '1-2', 2),
            ],
            Plateau::vide()
        );

        self::assertCount(8, $resolus['tableau']->entrants->refs());
        self::assertCount(8, $resolus['consolante']->entrants->refs());

        foreach ($resolus['consolante']->entrants->refs() as $ref) {
            self::assertTrue(
                str_starts_with($ref, '3') || str_starts_with($ref, '4'),
                "{$ref} ne devrait pas etre en consolante"
            );
        }
    }

    public function testUneEntiteNEstSelectionneeQueParUnSeulFlux(): void
    {
        $moteur = new MoteurFlux($this->poulesClosees());

        $resolus = $moteur->resoudre(
            [
                new Flux('poules', 'tableau', Selecteur::PlaceExacte, 1, 1),
                new Flux('poules', 'consolante', Selecteur::Tous, null, 2),
            ],
            Plateau::vide()
        );

        // RG-31 : les premiers sont partis au tableau, la consolante
        // recupere les douze autres, pas seize.
        self::assertCount(4, $resolus['tableau']->entrants->refs());
        self::assertCount(12, $resolus['consolante']->entrants->refs());
        self::assertNotContains('1A', $resolus['consolante']->entrants->refs());
    }

    public function testLeSurnombreDeclencheUnBarrage(): void
    {
        $moteur = new MoteurFlux($this->poulesClosees());

        // Huit candidats pour six places : deux de trop.
        $resolus = $moteur->resoudre(
            [new Flux(
                'poules',
                'tableau',
                Selecteur::PlacesDeA,
                '1-2',
                1,
                capaciteMax: 6,
                siSurnombre: Flux::SURNOMBRE_BARRAGE,
            )],
            Plateau::vide()
        );

        $resultat = $resolus['tableau'];

        self::assertTrue($resultat->exigeBarrage());

        // Quatre barragistes se disputent les deux dernieres places :
        // les deux derniers qualifies d'office cedent leur place.
        self::assertCount(4, $resultat->barrageRequis);
        self::assertSame(2, $resultat->placesRestantes);
        self::assertCount(4, $resultat->entrants->refs());
    }

    public function testLaTroncatureEcarteSansBarrage(): void
    {
        $moteur = new MoteurFlux($this->poulesClosees());

        $resolus = $moteur->resoudre(
            [new Flux(
                'poules',
                'tableau',
                Selecteur::PlacesDeA,
                '1-2',
                1,
                capaciteMax: 6,
                siSurnombre: Flux::SURNOMBRE_TRONQUER,
            )],
            Plateau::vide()
        );

        self::assertFalse($resolus['tableau']->exigeBarrage());
        self::assertCount(6, $resolus['tableau']->entrants->refs());
        self::assertCount(2, $resolus['tableau']->refusees);
    }

    public function testPerdantsTourAlimenteUneConsolanteSansAttendreLaCloture(): void
    {
        $resultats = new ResultatsEnMemoire();
        $resultats->tour('tableau', 1, ['1A', '1B'], ['2C', '2D']);
        // La phase n'est PAS close : `perdants_tour` doit fonctionner
        // quand meme, sinon la consolante ne pourrait jamais demarrer
        // avant la fin du tableau principal.

        $moteur  = new MoteurFlux($resultats);
        $resolus = $moteur->resoudre(
            [new Flux('tableau', 'consolante', Selecteur::PerdantsTour, 1)],
            Plateau::vide()
        );

        self::assertSame(['2C', '2D'], $resolus['consolante']->entrants->refs());
    }

    public function testLesSelecteursDeClassementAttendentLaCloture(): void
    {
        $resultats = new ResultatsEnMemoire();
        $resultats->classer('poules', 'A', ['1A', '2A']);
        $resultats->cloturer('poules', false);

        $moteur  = new MoteurFlux($resultats);
        $resolus = $moteur->resoudre(
            [new Flux('poules', 'tableau', Selecteur::PlaceExacte, 1)],
            Plateau::vide()
        );

        self::assertSame(0, $resolus['tableau']->effectif());
    }

    public function testEliminesAvecNDefaitesRouteLesVies(): void
    {
        $resultats = new ResultatsEnMemoire();
        $resultats->classer('tableau', 'principal', ['A', 'B', 'C', 'D']);
        $resultats->tour('tableau', 1, ['A', 'B'], ['C', 'D']);
        $resultats->tour('tableau', 2, ['A'], ['B']);

        $moteur  = new MoteurFlux($resultats);
        $resolus = $moteur->resoudre(
            [new Flux('tableau', 'repechage', Selecteur::EliminesAvecNDefaites, 1)],
            Plateau::vide()
        );

        $refs = $resolus['repechage']->entrants->refs();
        sort($refs);

        self::assertSame(['B', 'C', 'D'], $refs);
    }

    public function testMeilleursNiemesUtiliseLeClassementInterPoules(): void
    {
        $resultats = $this->poulesClosees();
        // Classement inter-poules : 2C est meilleur deuxieme, 2A le pire.
        $resultats->classerGlobal('poules', ['1A', '1B', '1C', '1D', '2C', '2B', '2D', '2A']);

        $moteur  = new MoteurFlux($resultats);
        $resolus = $moteur->resoudre(
            [new Flux('poules', 'tableau', Selecteur::MeilleursNiemes, '2:2')],
            Plateau::vide()
        );

        self::assertSame(['2C', '2B'], $resolus['tableau']->entrants->refs());
    }

    public function testDepuisInscriptionsPrendLePlateauInitial(): void
    {
        $moteur = new MoteurFlux(new ResultatsEnMemoire());

        $plateau = new Plateau([
            new Entite('X', 'X', 1),
            new Entite('Y', 'Y', 2),
        ]);

        $resolus = $moteur->resoudre(
            [new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous)],
            $plateau
        );

        self::assertSame(['X', 'Y'], $resolus['poules']->entrants->refs());
    }

    public function testLOrigineEstConserveePourLaSeparation(): void
    {
        $moteur  = new MoteurFlux($this->poulesClosees());
        $resolus = $moteur->resoudre(
            [new Flux('poules', 'tableau', Selecteur::PlacesDeA, '1-2')],
            Plateau::vide()
        );

        $entite = $resolus['tableau']->entrants->entite('2A');

        self::assertNotNull($entite);
        self::assertSame('A', $entite->origine);
    }
}
