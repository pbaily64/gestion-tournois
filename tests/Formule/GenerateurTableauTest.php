<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests\Formule;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Formule\Generation\GenerateurTableau;
use RMCF\Tournois\Formule\Generation\PlacementTableau;
use RMCF\Tournois\Formule\Parametres;
use RMCF\Tournois\Formule\Structure\Appariement;
use RMCF\Tournois\Formule\Structure\Emplacement;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * Les tableaux (§3.2 a §3.5).
 *
 * Une seule classe produit quatre formules ; les tests verifient donc
 * surtout que le parametre `defaites_tolerees` change bien de topologie,
 * et que chaque topologie produit le nombre de parties que la litterature
 * lui attribue — n-1, 2n-2, (n/2)·log2(n). Un ecart sur ces totaux est le
 * symptome le plus fiable d'une branche mal cablee.
 */
final class GenerateurTableauTest extends TestCase
{
    private function plateau(int $n, ?callable $origine = null): Plateau
    {
        $entites = [];

        for ($i = 1; $i <= $n; $i++) {
            $entites[] = new Entite(
                'J' . $i,
                'Joueur ' . $i,
                $i,
                20 - $i,
                $origine !== null ? $origine($i) : null
            );
        }

        return new Plateau($entites);
    }

    private function parametres(array $surcharges = []): Parametres
    {
        return Parametres::chaine(['type_phase' => 'tableau', ...$surcharges]);
    }

    // --- geometrie ---------------------------------------------------

    public function testLOrdreDesTetesDeSerieSuitLaRecurrenceStandard(): void
    {
        self::assertSame([1, 2], PlacementTableau::ordreSeeds(2));
        self::assertSame([1, 4, 2, 3], PlacementTableau::ordreSeeds(4));
        self::assertSame([1, 8, 4, 5, 2, 7, 3, 6], PlacementTableau::ordreSeeds(8));
    }

    public function testLesTetesDeSerieUnEtDeuxNePeuventSeRencontrerQuEnFinale(): void
    {
        $genere = (new GenerateurTableau())->generer('T', $this->plateau(16), $this->parametres());

        $premierTour = $genere->appariementsDuTour(1);

        self::assertCount(8, $premierTour);
        self::assertSame('J1', $premierTour[0]->a->reference);

        // La propriete a verifier n'est pas une position mais une
        // separation : les seeds 1 et 2 sont dans des moities opposees,
        // les seeds 1 a 4 dans des quarts distincts.
        $moitie = [];
        $quart  = [];

        foreach ($premierTour as $i => $appariement) {
            foreach ([$appariement->a, $appariement->b] as $cote) {
                if ($cote->estConnu()) {
                    $moitie[$cote->reference] = intdiv($i, 4);
                    $quart[$cote->reference]  = intdiv($i, 2);
                }
            }
        }

        self::assertTrue($moitie['J1'] !== $moitie['J2'], 'seeds 1 et 2 dans la meme moitie');

        $quarts = [$quart['J1'], $quart['J2'], $quart['J3'], $quart['J4']];
        self::assertCount(4, array_unique($quarts), 'seeds 1 a 4 pas tous dans des quarts distincts');
    }

    // --- elimination directe ----------------------------------------

    public function testSeizeEntrantsProduisentQuinzeParties(): void
    {
        $genere = (new GenerateurTableau())->generer('T', $this->plateau(16), $this->parametres());

        self::assertSame(15, $genere->nombreParties());
        self::assertSame(4, $genere->nombreTours());
        self::assertSame('Finale', $genere->tours[4]);
    }

    public function testOnzeEntrantsDonnentCinqExemptsAuxMieuxClasses(): void
    {
        $genere = (new GenerateurTableau())->generer('T', $this->plateau(11), $this->parametres());

        self::assertSame(16, $genere->meta['taille']);
        self::assertSame(5, $genere->meta['exempts']);

        // n-1 parties reellement jouees, quel que soit le nombre d'exempts.
        self::assertSame(10, $genere->nombreParties());

        $exemptes = array_values(array_filter(
            $genere->appariementsDuTour(1),
            static fn (Appariement $a): bool => $a->estExempt()
        ));

        self::assertCount(5, $exemptes);

        // Ce sont bien les cinq mieux classes qui en beneficient.
        $beneficiaires = array_map(
            static fn (Appariement $a): ?string => $a->beneficiaireExempt()?->reference,
            $exemptes
        );

        sort($beneficiaires);
        self::assertSame(['J1', 'J2', 'J3', 'J4', 'J5'], $beneficiaires);
    }

    public function testLesToursSuivantsSontExprimesEnProvenances(): void
    {
        $genere = (new GenerateurTableau())->generer('T', $this->plateau(8), $this->parametres());

        $demies = $genere->appariementsDuTour(2);

        self::assertCount(2, $demies);
        self::assertSame(Emplacement::VAINQUEUR, $demies[0]->a->nature);
        self::assertSame('T-T1-01', $demies[0]->a->reference);
        self::assertSame('T-T1-02', $demies[0]->b->reference);
        self::assertFalse($demies[0]->estLancable());
    }

    public function testLaPetiteFinaleOpposeLesPerdantsDesDemies(): void
    {
        $genere = (new GenerateurTableau())->generer('T', $this->plateau(8), $this->parametres([
            'petite_finale' => true,
        ]));

        self::assertSame(8, $genere->nombreParties()); // 7 + 1

        $pf = $genere->appariement('T-PF');

        self::assertNotNull($pf);
        self::assertSame(Emplacement::PERDANT, $pf->a->nature);
        self::assertSame(Appariement::ROLE_PETITE_FINALE, $pf->role);
    }

    // --- double elimination -----------------------------------------

    public function testLaDoubleEliminationProduitDeuxNMoinsDeuxParties(): void
    {
        $genere = (new GenerateurTableau())->generer('T', $this->plateau(8), $this->parametres([
            'defaites_tolerees'   => 2,
            'grande_finale_reset' => false,
        ]));

        self::assertSame('double_elimination', $genere->meta['topologie']);
        self::assertSame(14, $genere->nombreParties()); // 2n-2
    }

    public function testLaBelleAjouteUnePartieConditionnelle(): void
    {
        $genere = (new GenerateurTableau())->generer('T', $this->plateau(8), $this->parametres([
            'defaites_tolerees'   => 2,
            'grande_finale_reset' => true,
        ]));

        self::assertSame(15, $genere->nombreParties()); // 2n-1
        self::assertNotNull($genere->appariement('T-GF'));
        self::assertNotNull($genere->appariement('T-GF2'));
    }

    public function testLaBrancheDesPerdantsEstAlimenteeParDesPerdants(): void
    {
        $genere = (new GenerateurTableau())->generer('T', $this->plateau(8), $this->parametres([
            'defaites_tolerees' => 2,
        ]));

        $perdants = array_values(array_filter(
            $genere->appariements,
            static fn (Appariement $a): bool => $a->role === Appariement::ROLE_BRANCHE_PERDANTS
        ));

        self::assertCount(6, $perdants);

        // Le premier tour de la branche basse ne prend QUE des perdants.
        self::assertSame(Emplacement::PERDANT, $perdants[0]->a->nature);
        self::assertSame(Emplacement::PERDANT, $perdants[0]->b->nature);
    }

    public function testLeVolumeAnnonceEnDoubleEliminationEstExact(): void
    {
        $generateur = new GenerateurTableau();

        foreach ([4, 8, 16] as $effectif) {
            $parametres = $this->parametres([
                'defaites_tolerees'   => 2,
                'grande_finale_reset' => false,
            ]);

            self::assertSame(
                $generateur->volume($effectif, $parametres),
                $generateur->generer('T', $this->plateau($effectif), $parametres)->nombreParties(),
                "effectif {$effectif}"
            );
        }
    }

    // --- classement integral ----------------------------------------

    public function testLeClassementIntegralFaitJouerTouLeMondeAutantDeFois(): void
    {
        $genere = (new GenerateurTableau('classement_integral'))
            ->generer('CI', $this->plateau(8), $this->parametres(['type_phase' => 'classement_integral']));

        self::assertSame('classement_integral', $genere->meta['topologie']);
        self::assertSame(3, $genere->meta['parties_par_entite']);

        // (n/2) x log2(n) = 4 x 3
        self::assertSame(12, $genere->nombreParties());

        // Chaque tour fait jouer n/2 parties : personne ne se repose.
        for ($tour = 1; $tour <= 3; $tour++) {
            self::assertCount(4, $genere->appariementsDuTour($tour), "tour {$tour}");
        }
    }

    public function testLeClassementIntegralNommeLesPlacesEnJeu(): void
    {
        $genere = (new GenerateurTableau('classement_integral'))
            ->generer('CI', $this->plateau(8), $this->parametres(['type_phase' => 'classement_integral']));

        $enjeux = [];

        foreach ($genere->appariementsDuTour(3) as $appariement) {
            $enjeux[] = $appariement->enjeu;
        }

        self::assertSame(
            ['Places 1 a 2', 'Places 3 a 4', 'Places 5 a 6', 'Places 7 a 8'],
            $enjeux
        );
    }

    // --- separation --------------------------------------------------

    public function testLaSeparationEcarteLesQualifiesDeMemePoule(): void
    {
        // Huit qualifies, deux par poule : 1A, 1B, 1C, 1D, 2A, 2B, 2C, 2D.
        $entites = [];
        $poules  = ['A', 'B', 'C', 'D'];
        $rang    = 1;

        foreach ([1, 2] as $place) {
            foreach ($poules as $poule) {
                $entites[] = new Entite($place . $poule, $place . $poule, $rang++, 10, $poule);
            }
        }

        $genere = (new GenerateurTableau())->generer('T', new Plateau($entites), $this->parametres([
            'separer_meme_poule' => 'moitie',
        ]));

        $ordre = PlacementTableau::ordreSeeds(8);
        $zones = [];

        foreach ($genere->appariementsDuTour(1) as $i => $appariement) {
            foreach ([$appariement->a, $appariement->b] as $cote) {
                if ($cote->estConnu()) {
                    $zones[$cote->reference] = $i < 2 ? 'haute' : 'basse';
                }
            }
        }

        foreach ($poules as $poule) {
            self::assertTrue(
                ($zones['1' . $poule] ?? null) !== ($zones['2' . $poule] ?? null),
                "les deux qualifies de la poule {$poule} sont dans la meme moitie"
            );
        }
    }
}
