<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests\Formule;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Formule\Generation\GenerateurPoules;
use RMCF\Tournois\Formule\Generation\OrdreParties;
use RMCF\Tournois\Formule\Parametres;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * Le generateur de poules (§3.1).
 *
 * Les tests portent sur les trois decisions qu'il prend : combien de
 * poules, qui va ou, dans quel ordre on joue. Ce sont les trois endroits
 * ou une erreur ne se verrait qu'au moment de distribuer les feuilles de
 * match.
 */
final class GenerateurPoulesTest extends TestCase
{
    private function plateau(int $n): Plateau
    {
        $entites = [];

        for ($i = 1; $i <= $n; $i++) {
            $entites[] = new Entite('J' . $i, 'Joueur ' . $i, $i, 20 - $i);
        }

        return new Plateau($entites);
    }

    private function parametres(array $surcharges = []): Parametres
    {
        return Parametres::chaine(['type_phase' => 'poules', ...$surcharges]);
    }

    public function testSeizeInscritsDonnentQuatrePoulesDeQuatre(): void
    {
        $genere = (new GenerateurPoules())->generer('P', $this->plateau(16), $this->parametres());

        self::assertCount(4, $genere->groupes);

        foreach ($genere->groupes as $membres) {
            self::assertCount(4, $membres);
        }

        // 6 parties par poule de 4, quatre poules.
        self::assertSame(24, $genere->nombreParties());
        self::assertSame([], $genere->avertissements);
    }

    public function testLeSerpentinRepartitLesTetesDeSerie(): void
    {
        $genere = (new GenerateurPoules())->generer('P', $this->plateau(8), $this->parametres([
            'nb_groupes' => 2,
        ]));

        // p = i mod 2n : 1 et 4 en A, 2 et 3 en B pour n = 2.
        self::assertSame(['J1', 'J4', 'J5', 'J8'], $genere->groupes['A']);
        self::assertSame(['J2', 'J3', 'J6', 'J7'], $genere->groupes['B']);
    }

    public function testTaillesInegalesRespectentLaTolerance(): void
    {
        $genere = (new GenerateurPoules())->generer('P', $this->plateau(14), $this->parametres());

        $tailles = array_map('count', $genere->groupes);

        self::assertLessThanOrEqual(1, max($tailles) - min($tailles));
        self::assertSame(14, array_sum($tailles));
    }

    public function testNombreDePoulesImposeParLaTailleDeGroupe(): void
    {
        $genere = (new GenerateurPoules())->generer('P', $this->plateau(18), $this->parametres([
            'taille_groupe' => 6,
        ]));

        self::assertCount(3, $genere->groupes);
        self::assertSame(45, $genere->nombreParties()); // 15 parties x 3
    }

    public function testDernierePartieOpposeLesDeuxPremiersQuandUnSeulSeQualifie(): void
    {
        $genere = (new GenerateurPoules())->generer('P', $this->plateau(4), $this->parametres([
            'nb_groupes'               => 1,
            'nb_qualifies'             => 1,
            'derniere_partie_decisive' => true,
        ]));

        $parties  = $genere->appariementsDuGroupe('A');
        $derniere = $parties[count($parties) - 1];

        // Les places 1 et 2 de la poule, soit les seeds J1 et J2.
        $camps = [$derniere->a->reference, $derniere->b->reference];
        sort($camps);

        self::assertSame(['J1', 'J2'], $camps);
    }

    public function testDernierePartieOpposeLesPlacesDeuxEtTroisQuandDeuxSeQualifient(): void
    {
        $genere = (new GenerateurPoules())->generer('P', $this->plateau(5), $this->parametres([
            'nb_groupes'               => 1,
            'nb_qualifies'             => 2,
            'derniere_partie_decisive' => true,
        ]));

        $parties  = $genere->appariementsDuGroupe('A');
        $derniere = $parties[count($parties) - 1];

        $camps = [$derniere->a->reference, $derniere->b->reference];
        sort($camps);

        self::assertSame(['J2', 'J3'], $camps);
    }

    public function testLaSeparationParClubEcarteLesCoequipiers(): void
    {
        $entites = [
            new Entite('A1', 'A1', 1, 10, null, 1, 'Falisolle'),
            new Entite('B1', 'B1', 2, 10, null, 1, 'Namur'),
            new Entite('A2', 'A2', 3, 10, null, 1, 'Falisolle'),
            new Entite('B2', 'B2', 4, 10, null, 1, 'Namur'),
        ];

        $genere = (new GenerateurPoules())->generer('P', new Plateau($entites), $this->parametres([
            'nb_groupes'          => 2,
            'criteres_separation' => ['meme_club'],
        ]));

        foreach ($genere->groupes as $membres) {
            self::assertCount(2, $membres);
            self::assertTrue(
                $membres !== ['A1', 'A2'] && $membres !== ['B1', 'B2'],
                'deux joueurs du meme club dans la meme poule'
            );
        }
    }

    public function testLeVolumeAnnonceCorrespondAuVolumeGenere(): void
    {
        $generateur = new GenerateurPoules();
        $parametres = $this->parametres();

        foreach ([9, 12, 15, 20, 24, 31] as $effectif) {
            $annonce = $generateur->volume($effectif, $parametres);
            $reel    = $generateur->generer('P', $this->plateau($effectif), $parametres)->nombreParties();

            self::assertSame($annonce, $reel, "effectif {$effectif}");
        }
    }

    public function testLaRondeDeBergerCouvreLesTaillesHorsSequencesOfficielles(): void
    {
        // Taille 10 : aucune sequence officielle, repli sur Berger.
        $sequence = OrdreParties::pour(10);

        self::assertCount(45, $sequence);

        // Chaque paire apparait exactement une fois.
        $vues = [];

        foreach ($sequence as $partie) {
            $paire = [$partie[0], $partie[1]];
            sort($paire);
            $cle = implode('-', $paire);

            self::assertFalse(isset($vues[$cle]), "paire {$cle} en double");
            $vues[$cle] = true;
        }
    }
}
