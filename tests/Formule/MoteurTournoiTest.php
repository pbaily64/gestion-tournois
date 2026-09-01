<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests\Formule;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Formule\Deroulement\DefinitionPhase;
use RMCF\Tournois\Formule\Deroulement\DefinitionTournoi;
use RMCF\Tournois\Formule\Deroulement\MoteurTournoi;
use RMCF\Tournois\Formule\Deroulement\Prereglages;
use RMCF\Tournois\Formule\Flux\Flux;
use RMCF\Tournois\Formule\Flux\ResultatsEnMemoire;
use RMCF\Tournois\Formule\Flux\Selecteur;
use RMCF\Tournois\Formule\Generation\GenerateurCroise;
use RMCF\Tournois\Formule\Structure\Emplacement;
use RMCF\Tournois\Formule\Parametres;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * L'orchestrateur, bout en bout, et l'appariement croise.
 *
 * Ces tests partent des prereglages du §11 : verifier qu'un « MbN
 * classique » se genere correctement pour 12, 24 et 40 inscrits vaut
 * mieux que verifier chaque parametre isolement, parce que c'est
 * exactement ce qui se passe un vendredi soir.
 */
final class MoteurTournoiTest extends TestCase
{
    private function inscrits(int $n): Plateau
    {
        $entites = [];

        for ($i = 1; $i <= $n; $i++) {
            $entites[] = new Entite('J' . $i, 'Joueur ' . $i, $i, max(0, 17 - intdiv($i, 3)));
        }

        return new Plateau($entites);
    }

    private function moteur(?ResultatsEnMemoire $resultats = null): MoteurTournoi
    {
        return new MoteurTournoi($resultats ?? new ResultatsEnMemoire(), 42);
    }

    // --- activation conditionnelle -----------------------------------

    public function testUnePhaseConditionnelleEstIgnoreeQuandLaConditionEstFausse(): void
    {
        $genere = $this->moteur()->generer(Prereglages::mbnClassique(), $this->inscrits(12));

        // « nb_inscrits > 16 » est faux : pas de consolante (RG-21).
        self::assertTrue(isset($genere->ignorees['consolante']));
        self::assertNull($genere->phase('consolante'));
    }

    public function testLaMemeDefinitionActiveLaConsolanteQuandLePlateauGrossit(): void
    {
        $genere = $this->moteur()->generer(Prereglages::mbnClassique(), $this->inscrits(32));

        self::assertFalse(isset($genere->ignorees['consolante']));
        self::assertNotNull($genere->phase('consolante'));
    }

    // --- MbN complet --------------------------------------------------

    public function testLeMbnClassiqueGenereSesPoulesDesLOuverture(): void
    {
        $genere = $this->moteur()->generer(Prereglages::mbnClassique(), $this->inscrits(24));

        $poules = $genere->phase('poules');

        self::assertNotNull($poules);
        self::assertCount(6, $poules->groupes);
        self::assertSame(36, $poules->nombreParties()); // 6 poules de 4

        // Les poules sont immediatement lancables, le tableau non :
        // il attend ses qualifies.
        self::assertSame(36, count($genere->lancables()));
    }

    public function testLeTableauSeRemplitUneFoisLesPoulesClosees(): void
    {
        $resultats = new ResultatsEnMemoire();

        // Six poules de quatre, closes, classement declare.
        for ($p = 0; $p < 6; $p++) {
            $poule = chr(ord('A') + $p);
            $resultats->classer('poules', $poule, array_map(
                static fn (int $place): string => $place . $poule,
                [1, 2, 3, 4]
            ));
        }

        $genere = $this->moteur($resultats)
            ->generer(Prereglages::mbnClassique(), $this->inscrits(24));

        $tableau = $genere->phase('tableau');

        self::assertNotNull($tableau);

        // Douze qualifies dans un tableau de 16 : quatre exempts.
        self::assertSame(16, $tableau->meta['taille']);
        self::assertSame(4, $tableau->meta['exempts']);
        self::assertSame(11, $tableau->nombreParties());

        // La consolante recupere les douze non-qualifies.
        $consolante = $genere->phase('consolante');

        self::assertNotNull($consolante);
        self::assertSame(11, $consolante->nombreParties());
    }

    public function testLeSurnombreIntercaleUnBarrageAutomatiquement(): void
    {
        $resultats = new ResultatsEnMemoire();

        // Dix poules : vingt qualifies pour seize places.
        for ($p = 0; $p < 10; $p++) {
            $poule = chr(ord('A') + $p);
            $resultats->classer('poules', $poule, array_map(
                static fn (int $place): string => $place . $poule,
                [1, 2, 3, 4]
            ));
        }

        $genere = $this->moteur($resultats)
            ->generer(Prereglages::mbnClassique(), $this->inscrits(40));

        // RG-33 : une phase de barrage apparait sans avoir ete declaree.
        $barrage = $genere->phase('tableau_barrage');

        self::assertNotNull($barrage);
        self::assertSame('barrage', $barrage->type);
        self::assertSame(8, count($barrage->groupes['barrage']));
        self::assertSame(4, $barrage->meta['places_a_pourvoir']);
    }

    public function testLeBarrageSArreteDesQueLesPlacesSontPourvues(): void
    {
        $resultats = new ResultatsEnMemoire();

        for ($p = 0; $p < 10; $p++) {
            $poule = chr(ord('A') + $p);
            $resultats->classer('poules', $poule, array_map(
                static fn (int $place): string => $place . $poule,
                [1, 2, 3, 4]
            ));
        }

        $genere  = $this->moteur($resultats)
            ->generer(Prereglages::mbnClassique(), $this->inscrits(40));
        $barrage = $genere->phase('tableau_barrage');

        self::assertNotNull($barrage);

        // Huit barragistes pour quatre places : UN tour de quatre
        // parties. Un tableau complet en produirait sept et designerait
        // un « vainqueur du barrage » sans existence réglementaire.
        self::assertSame(4, $barrage->nombreParties());
        self::assertSame(1, $barrage->nombreTours());
    }

    public function testLeTableauReserveLesPlacesDesQualifiesDuBarrage(): void
    {
        $resultats = new ResultatsEnMemoire();

        for ($p = 0; $p < 10; $p++) {
            $poule = chr(ord('A') + $p);
            $resultats->classer('poules', $poule, array_map(
                static fn (int $place): string => $place . $poule,
                [1, 2, 3, 4]
            ));
        }

        $genere  = $this->moteur($resultats)
            ->generer(Prereglages::mbnClassique(), $this->inscrits(40));
        $tableau = $genere->phase('tableau');

        self::assertNotNull($tableau);

        // Douze qualifies directs + quatre places de barrage : le
        // tableau de 16 est plein, sans aucun exempt. Sans reservation
        // il se genererait a douze entrants et les qualifies du barrage
        // n'auraient nulle part ou entrer.
        self::assertSame(16, $tableau->meta['taille']);
        self::assertSame(0, $tableau->meta['exempts']);
        self::assertSame(15, $tableau->nombreParties());

        $membres  = $tableau->groupes['principal'];
        $reservees = array_values(array_filter(
            $membres,
            static fn (string $ref): bool => str_contains($ref, 'tableau_barrage#')
        ));

        self::assertCount(4, $reservees);
    }

    public function testUnePlaceDeBarrageNEstPasLancable(): void
    {
        // LE TEST QUI MANQUAIT. Le precedent verifiait que le tableau
        // fait bien 16 places sans exempt — ce qui etait vrai — mais
        // aucun ne verifiait que ces places sont JOUABLES. Les quatre
        // matchs adosses au barrage etaient annonces lancables, et la
        // table de marque aurait appele un joueur contre un adversaire
        // encore en train de disputer son barrage.
        $resultats = new ResultatsEnMemoire();

        for ($p = 0; $p < 10; $p++) {
            $poule = chr(ord('A') + $p);
            $resultats->classer('poules', $poule, array_map(
                static fn (int $place): string => $place . $poule,
                [1, 2, 3, 4]
            ));
        }

        $genere  = $this->moteur($resultats)
            ->generer(Prereglages::mbnClassique(), $this->inscrits(40));
        $tableau = $genere->phase('tableau');

        self::assertNotNull($tableau);

        $premierTour = $tableau->appariementsDuTour(1);

        self::assertCount(8, $premierTour);

        $enAttente = 0;

        foreach ($premierTour as $appariement) {
            $adossee = false;

            foreach ([$appariement->a, $appariement->b] as $cote) {
                if (str_contains((string) $cote->reference, 'tableau_barrage#')) {
                    $adossee = true;

                    // La place doit etre exprimee comme « a pourvoir »,
                    // pas comme un camp connu.
                    self::assertSame(Emplacement::QUALIFIE, $cote->nature);
                }
            }

            if ($adossee) {
                $enAttente++;
                self::assertFalse(
                    $appariement->estLancable(),
                    $appariement->id . ' ne devrait pas être lançable'
                );
            }
        }

        self::assertSame(4, $enAttente);

        // Le tableau garde sa taille : la correction ne doit pas
        // reintroduire le defaut qu'elle avait repare.
        self::assertSame(16, $tableau->meta['taille']);
        self::assertSame(15, $tableau->nombreParties());
    }

    public function testLEstimationCorrespondAuVolumeReellementGenere(): void
    {
        // Le test qui protege RG-91 : une estimation fausse une fois et
        // l'organisateur n'ouvrira plus jamais l'ecran de verification.
        $definition = Prereglages::mbnClassique();

        foreach ([16, 20, 24, 32, 40, 48] as $effectif) {
            $estimation = $this->moteur()->estimer($definition, $effectif);

            $resultats = new ResultatsEnMemoire();
            $moteur    = new MoteurTournoi($resultats, 42);
            $inscrits  = $this->inscrits($effectif);

            // Premier passage : on cloture les poules telles que generees.
            $etape = $moteur->generer($definition, $inscrits);

            foreach ($etape->phase('poules')?->groupes ?? [] as $libelle => $membres) {
                $resultats->classer('poules', $libelle, $membres);
            }

            $complet = $moteur->generer($definition, $inscrits);

            self::assertSame(
                $estimation['parties'],
                $complet->nombreParties(),
                "effectif {$effectif}"
            );
        }
    }

    // --- prereglages --------------------------------------------------

    public function testTousLesPrereglagesSeGenerentSansErreur(): void
    {
        foreach (array_keys(Prereglages::catalogue()) as $code) {
            $definition = Prereglages::parCode($code);
            $genere     = $this->moteur()->generer($definition, $this->inscrits(16));

            self::assertGreaterThan(0, $genere->nombreParties(), "prereglage {$code}");
        }
    }

    public function testLaSoireeExpressEstUnePouleUnique(): void
    {
        $genere = $this->moteur()->generer(Prereglages::soireeExpress(), $this->inscrits(6));

        self::assertCount(1, $genere->phases);
        self::assertSame(15, $genere->nombreParties()); // 6x5/2
    }

    public function testToutesViesEstUneDoubleElimination(): void
    {
        $genere = $this->moteur()->generer(Prereglages::toutesVies(), $this->inscrits(8));

        self::assertSame(
            'double_elimination',
            $genere->phase('tableau')?->meta['topologie']
        );
        self::assertSame(15, $genere->nombreParties()); // 2n-1 avec la belle
    }

    // --- estimation de volume ----------------------------------------

    public function testLEstimationDeVolumeAnnonceUneDuree(): void
    {
        $estimation = $this->moteur()->estimer(Prereglages::mbnClassique(), 24);

        self::assertGreaterThan(0, $estimation['parties']);
        self::assertSame($estimation['parties'] * 12, $estimation['duree_minutes']);
        self::assertTrue(isset($estimation['par_phase']['poules']));
    }

    // --- redirection de flux ------------------------------------------

    public function testUnFluxVersUnePhaseInactiveEstAbandonne(): void
    {
        $definition = new DefinitionTournoi(
            code: 'test',
            parametres: ['libelle' => 'Test'],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, ['nb_groupes' => 2]),
                new DefinitionPhase('extra', 'tableau', 2, [], null, false, 'nb_inscrits > 100'),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous, null, 1),
                new Flux('poules', 'extra', Selecteur::PlaceExacte, 1, 2),
            ],
        );

        $genere = $this->moteur()->generer($definition, $this->inscrits(8));

        self::assertTrue(isset($genere->ignorees['extra']));
        self::assertNotEmpty($genere->avertissements);
    }

    // --- appariement croise (Scheveningen) ----------------------------

    public function testLAppariementCroiseFaitJouerChacunContreChacunDeLAutreGroupe(): void
    {
        $entites = [];

        foreach (['A', 'B'] as $groupe) {
            for ($i = 1; $i <= 4; $i++) {
                $entites[] = new Entite($groupe . $i, $groupe . $i, $i, 10, $groupe);
            }
        }

        $genere = (new GenerateurCroise())->generer(
            'X',
            new Plateau($entites),
            Parametres::chaine(['type_phase' => 'croise'])
        );

        // 4 x 4 = 16 parties, en 4 tours de 4.
        self::assertSame(16, $genere->nombreParties());
        self::assertCount(4, $genere->tours);

        foreach (array_keys($genere->tours) as $tour) {
            self::assertCount(4, $genere->appariementsDuTour($tour), "tour {$tour}");
        }

        // Personne ne rencontre quelqu'un de son propre groupe, et
        // chaque paire A-B apparait exactement une fois.
        $vues = [];

        foreach ($genere->appariements as $appariement) {
            $a = (string) $appariement->a->reference;
            $b = (string) $appariement->b->reference;

            self::assertTrue($a[0] !== $b[0], "{$a} contre {$b} : meme groupe");

            $cle = $a . '-' . $b;
            self::assertFalse(isset($vues[$cle]), "paire {$cle} en double");
            $vues[$cle] = true;
        }
    }

    public function testLeCroiseSansGroupeDeclareCoupeLePlateauEnDeux(): void
    {
        $genere = (new GenerateurCroise())->generer(
            'X',
            $this->inscrits(6),
            Parametres::chaine(['type_phase' => 'croise'])
        );

        self::assertSame(9, $genere->nombreParties()); // 3 x 3
        self::assertNotEmpty($genere->avertissements);
    }
}
