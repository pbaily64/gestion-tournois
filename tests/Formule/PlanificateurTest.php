<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests\Formule;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Formule\Deroulement\MoteurTournoi;
use RMCF\Tournois\Formule\Deroulement\Prereglages;
use RMCF\Tournois\Formule\Flux\ResultatsEnMemoire;
use RMCF\Tournois\Formule\Parametres;
use RMCF\Tournois\Formule\Planification\Affectation;
use RMCF\Tournois\Formule\Planification\Planificateur;
use RMCF\Tournois\Formule\Structure\Appariement;
use RMCF\Tournois\Formule\Structure\Emplacement;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * La planification des tables (C.11).
 *
 * RG-90 est la regle a ne jamais laisser passer : lancer une partie dont
 * un joueur est deja engage ailleurs desorganise la soiree entiere, et
 * l'erreur ne se voit qu'au moment ou l'on appelle les joueurs.
 */
final class PlanificateurTest extends TestCase
{
    private function partie(string $id, string $a, string $b): Appariement
    {
        return new Appariement(
            id: $id,
            phase: 'P',
            a: Emplacement::ref($a),
            b: Emplacement::ref($b),
        );
    }

    public function testUnJoueurDejaEngageNEstPasRelance(): void
    {
        $planificateur = new Planificateur(nbTables: 4);

        $enCours = [$this->partie('M1', 'A', 'B')];

        $candidats = [
            $this->partie('M2', 'A', 'C'),   // A joue deja
            $this->partie('M3', 'D', 'E'),   // libre
        ];

        $affectations = $planificateur->lancer($candidats, $enCours);

        self::assertCount(1, $affectations);
        self::assertSame('M3', $affectations[0]->appariement->id);
    }

    public function testDeuxCandidatsPartageantUnJoueurNeSontPasLancesEnsemble(): void
    {
        $planificateur = new Planificateur(nbTables: 4);

        $affectations = $planificateur->lancer([
            $this->partie('M1', 'A', 'B'),
            $this->partie('M2', 'A', 'C'),   // A vient d'etre engage
            $this->partie('M3', 'D', 'E'),
        ], []);

        $ids = array_map(
            static fn (Affectation $a): string => $a->appariement->id,
            $affectations
        );

        self::assertSame(['M1', 'M3'], $ids);
    }

    public function testOnNeLancePasPlusDePartiesQueDeTablesLibres(): void
    {
        $planificateur = new Planificateur(nbTables: 2);

        $affectations = $planificateur->lancer([
            $this->partie('M1', 'A', 'B'),
            $this->partie('M2', 'C', 'D'),
            $this->partie('M3', 'E', 'F'),
        ], []);

        self::assertCount(2, $affectations);
        self::assertSame(1, $affectations[0]->table);
        self::assertSame(2, $affectations[1]->table);
    }

    public function testLesTablesOccupeesReduisentLesPlacesDisponibles(): void
    {
        $planificateur = new Planificateur(nbTables: 3);

        $affectations = $planificateur->lancer(
            [$this->partie('M3', 'E', 'F'), $this->partie('M4', 'G', 'H')],
            [$this->partie('M1', 'A', 'B'), $this->partie('M2', 'C', 'D')]
        );

        self::assertCount(1, $affectations);
        self::assertSame(3, $affectations[0]->table);
    }

    public function testLeReposInsuffisantAlerteSansBloquer(): void
    {
        $planificateur = new Planificateur(
            nbTables: 2,
            reposMinimum: 5,
            dernierePartieTerminee: ['A' => 100],
        );

        $affectations = $planificateur->lancer([$this->partie('M1', 'A', 'B')], [], 102);

        self::assertCount(1, $affectations);
        self::assertNotNull($affectations[0]->alerte);
        self::assertStringContainsString('repos', $affectations[0]->alerte);
    }

    public function testUnExemptNeConsommePasDeTable(): void
    {
        $planificateur = new Planificateur(nbTables: 2);

        $exempt = new Appariement(
            id: 'BYE',
            phase: 'T',
            a: Emplacement::ref('A'),
            b: Emplacement::vide(),
        );

        $affectations = $planificateur->lancer([$exempt, $this->partie('M1', 'B', 'C')], []);

        self::assertCount(1, $affectations);
        self::assertSame('M1', $affectations[0]->appariement->id);
    }

    public function testUnePartieDontLAdversaireEstInconnuNEstPasLancable(): void
    {
        $planificateur = new Planificateur(nbTables: 2);

        $differe = new Appariement(
            id: 'T2',
            phase: 'T',
            a: Emplacement::vainqueurDe('T1-01'),
            b: Emplacement::vainqueurDe('T1-02'),
        );

        self::assertCount(0, $planificateur->lancer([$differe], []));
    }

    public function testLEstimationDeSoireeCorrespondAuVolumeGenere(): void
    {
        $moteur = new MoteurTournoi(new ResultatsEnMemoire(), 42);
        $genere = $moteur->generer(Prereglages::mbnClassique(), $this->inscrits(24));

        $planificateur = new Planificateur(nbTables: 6);
        $estimation    = $planificateur->estimer(
            $genere->phase('poules')?->appariements ?? [],
            Parametres::chaine(['duree_estimee_partie' => 12])
        );

        self::assertSame(36, $estimation['parties']);
        self::assertSame(6, $estimation['par_table']);      // 36 / 6 tables
        self::assertSame(72, $estimation['minutes']);       // 6 x 12 min
    }

    private function inscrits(int $n): Plateau
    {
        $entites = [];

        for ($i = 1; $i <= $n; $i++) {
            $entites[] = new Entite('J' . $i, 'Joueur ' . $i, $i);
        }

        return new Plateau($entites);
    }
}
