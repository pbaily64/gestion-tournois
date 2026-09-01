<?php

declare(strict_types=1);

namespace RMCF\Tournois\Tests\Formule;

use PHPUnit\Framework\TestCase;
use RMCF\Tournois\Formule\Deroulement\DefinitionPhase;
use RMCF\Tournois\Formule\Deroulement\DefinitionTournoi;
use RMCF\Tournois\Formule\Deroulement\Prereglages;
use RMCF\Tournois\Formule\Flux\Flux;
use RMCF\Tournois\Formule\Flux\Selecteur;
use RMCF\Tournois\Formule\Validation\Anomalie;
use RMCF\Tournois\Formule\Validation\Validateur;

/**
 * Le controle avant ouverture (§10.3).
 *
 * Un validateur se teste dans les deux sens, et le premier sens compte
 * autant que le second : il doit laisser passer les configurations
 * legitimes. Un validateur qui bloque un tournoi correct est plus
 * nuisible qu'un validateur absent, parce qu'il apprend a l'organisateur
 * a ignorer ses messages.
 */
final class ValidateurTest extends TestCase
{
    /** @param list<Anomalie> $anomalies */
    private function messages(array $anomalies, string $niveau): array
    {
        return array_values(array_map(
            static fn (Anomalie $a): string => $a->message,
            array_filter($anomalies, static fn (Anomalie $a): bool => $a->niveau === $niveau)
        ));
    }

    /** @param list<Anomalie> $anomalies */
    private function regles(array $anomalies): array
    {
        return array_values(array_filter(array_map(
            static fn (Anomalie $a): ?string => $a->regle,
            $anomalies
        )));
    }

    // --- les prereglages doivent passer ------------------------------

    public function testTousLesPrereglagesSontOuvrables(): void
    {
        $validateur = new Validateur();

        foreach (array_keys(Prereglages::catalogue()) as $code) {
            $anomalies = $validateur->valider(Prereglages::parCode($code));

            self::assertTrue(
                $validateur->ouvrable($anomalies),
                "prereglage {$code} bloque : " . implode(' / ', $this->messages($anomalies, Anomalie::BLOQUANT))
            );
        }
    }

    public function testDeuxManchesGagnantesEstUnFormatLegitime(): void
    {
        // « Au meilleur des 3 » est le format le plus joue au monde :
        // le validateur ne doit surtout pas le refuser.
        $validateur = new Validateur();
        $anomalies  = $validateur->valider(Prereglages::soireeExpress());

        self::assertTrue($validateur->ouvrable($anomalies));
    }

    // --- RG-30 : phase inatteignable ---------------------------------

    public function testUnePhaseSansFluxEntrantEstBloquante(): void
    {
        $definition = new DefinitionTournoi(
            code: 'orphelin',
            parametres: ['libelle' => 'Test'],
            phases: [
                new DefinitionPhase('poules', 'poules', 1),
                new DefinitionPhase('tableau', 'tableau', 2),
            ],
            flux: [new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous)],
        );

        $validateur = new Validateur();
        $anomalies  = $validateur->valider($definition);

        self::assertFalse($validateur->ouvrable($anomalies));
        self::assertContains('RG-30', $this->regles($anomalies));
    }

    public function testUnCycleDeFluxEstBloquant(): void
    {
        $definition = new DefinitionTournoi(
            code: 'cycle',
            parametres: ['libelle' => 'Test'],
            phases: [
                new DefinitionPhase('a', 'poules', 1),
                new DefinitionPhase('b', 'poules', 2),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'a', Selecteur::Tous, null, 1),
                new Flux('a', 'b', Selecteur::PlaceExacte, 1, 2),
                new Flux('b', 'a', Selecteur::PlaceExacte, 1, 3),
            ],
        );

        $validateur = new Validateur();

        self::assertFalse($validateur->ouvrable($validateur->valider($definition)));
    }

    public function testUnePhaseCibleInconnueEstBloquante(): void
    {
        $definition = new DefinitionTournoi(
            code: 'fantome',
            parametres: ['libelle' => 'Test'],
            phases: [new DefinitionPhase('poules', 'poules', 1)],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous, null, 1),
                new Flux('poules', 'inexistante', Selecteur::PlaceExacte, 1, 2),
            ],
        );

        $validateur = new Validateur();

        self::assertFalse($validateur->ouvrable($validateur->valider($definition)));
    }

    // --- RG-51 : pas d'ex aequo ---------------------------------------

    public function testUneCascadeSansCritereTotalEstBloquante(): void
    {
        $definition = new DefinitionTournoi(
            code: 'exaequo',
            parametres: ['libelle' => 'Test'],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, [
                    'criteres'           => ['victoires', 'diff_manches'],
                    'interdire_ex_aequo' => true,
                ]),
            ],
            flux: [new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous)],
        );

        $validateur = new Validateur();
        $anomalies  = $validateur->valider($definition);

        self::assertFalse($validateur->ouvrable($anomalies));
        self::assertContains('RG-51', $this->regles($anomalies));
    }

    public function testLeClassementOfficielSeulNeSuffitPasCommeBackstop(): void
    {
        // Deux joueurs peuvent partager le meme classement AFTT : le
        // critere n'est donc PAS total. Le backstop du document est
        // « classement officiel PUIS alphabetique », et c'est bien la
        // paire qui est exigee.
        $incomplet = new DefinitionTournoi(
            code: 'incomplet',
            parametres: ['libelle' => 'Test'],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, [
                    'criteres'           => ['victoires', 'classement_officiel'],
                    'interdire_ex_aequo' => true,
                ]),
            ],
            flux: [new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous)],
        );

        $validateur = new Validateur();

        self::assertFalse($validateur->ouvrable($validateur->valider($incomplet)));
    }

    public function testUneCascadeSeTerminantSurLAlphabetiquePasse(): void
    {
        $definition = new DefinitionTournoi(
            code: 'ok',
            parametres: ['libelle' => 'Test'],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, [
                    'criteres'           => [
                        'victoires', 'diff_manches', 'classement_officiel', 'alphabetique',
                    ],
                    'interdire_ex_aequo' => true,
                ]),
            ],
            flux: [new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous)],
        );

        $validateur = new Validateur();

        self::assertTrue($validateur->ouvrable($validateur->valider($definition)));
    }

    // --- RG-70/71/77 : handicap ---------------------------------------

    public function testUnHandicapActifSansFormuleEstBloquant(): void
    {
        $definition = new DefinitionTournoi(
            code: 'handicap',
            parametres: [
                'libelle'        => 'Test',
                'handicap_actif' => true,
                'mode_calcul'    => 'formule',
                'formule'        => '',
            ],
            phases: [new DefinitionPhase('poules', 'poules', 1)],
            flux: [new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous)],
        );

        $validateur = new Validateur();
        $anomalies  = $validateur->valider($definition);

        self::assertFalse($validateur->ouvrable($anomalies));
        self::assertContains('RG-70', $this->regles($anomalies));
    }

    public function testUneFormuleDeHandicapInvalideEstBloquante(): void
    {
        $definition = new DefinitionTournoi(
            code: 'handicap',
            parametres: [
                'libelle'        => 'Test',
                'handicap_actif' => true,
                'formule'        => 'min(8; abs(e)/',
            ],
            phases: [new DefinitionPhase('poules', 'poules', 1)],
            flux: [new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous)],
        );

        $validateur = new Validateur();

        self::assertFalse($validateur->ouvrable($validateur->valider($definition)));
    }

    public function testUnPlafondTropHautPourLaMancheEstSignale(): void
    {
        // 8 points d'avance sur des manches a 11 : la manche est jouee
        // d'avance. Avertissement, pas blocage — c'est le choix du club.
        $anomalies = (new Validateur())->valider(Prereglages::mbnClassique());

        self::assertContains('RG-77', $this->regles($anomalies));
    }

    public function testRG77SeDeclencheAussiSurLesBaremesReelsAManchesLongues(): void
    {
        // 18 points d'avance sur une manche a 31 : c'est le bareme de
        // Bethune, documente au §6.3, et RG-77 le signale malgre tout
        // puisque 18 > 31/2. Le seuil de la regle est donc plus severe
        // que la pratique reelle des tournois a handicap.
        //
        // On teste le comportement CONFORME A LA SPECIFICATION, et le
        // point est remonte a part : c'est un avertissement, jamais un
        // blocage, donc l'organisateur reste libre.
        $validateur = new Validateur();
        $anomalies  = $validateur->valider(Prereglages::handicapOuvert());

        self::assertContains('RG-77', $this->regles($anomalies));
        self::assertTrue($validateur->ouvrable($anomalies));
    }

    // --- RG-91 : volume ------------------------------------------------

    public function testLeVolumeEstEstimeQuandLeNombreDInscritsEstConnu(): void
    {
        $definition = Prereglages::mbnClassique()->avecParametres([
            'nb_tables'    => 6,
            'heure_limite' => '23:30',
        ]);

        $anomalies = (new Validateur())->valider($definition, 24);
        $messages  = $this->messages($anomalies, Anomalie::INFORMATION);

        $trouve = false;

        foreach ($messages as $message) {
            if (str_contains($message, 'Volume estimé')) {
                $trouve = true;
            }
        }

        self::assertTrue($trouve, 'aucune estimation de volume produite');
    }

    public function testUneSoireeImpossibleEstSignalee(): void
    {
        // 48 inscrits sur 2 tables jusqu'a 22h : intenable.
        $definition = Prereglages::mbnClassique()->avecParametres([
            'nb_tables'    => 2,
            'heure_limite' => '22:00',
        ]);

        $anomalies = (new Validateur())->valider($definition, 48);

        self::assertContains('RG-91', $this->regles($anomalies));

        $trouve = false;

        foreach ($this->messages($anomalies, Anomalie::AVERTISSEMENT) as $message) {
            if (str_contains($message, 'heure limite')) {
                $trouve = true;
            }
        }

        self::assertTrue($trouve, 'le depassement horaire n\'est pas signale');
    }

    // --- catalogue -----------------------------------------------------

    public function testUneValeurHorsDomaineEstBloquante(): void
    {
        $definition = new DefinitionTournoi(
            code: 'domaine',
            parametres: ['libelle' => 'Test', 'type_entite' => 'trio'],
            phases: [new DefinitionPhase('poules', 'poules', 1)],
            flux: [new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous)],
        );

        $validateur = new Validateur();

        self::assertFalse($validateur->ouvrable($validateur->valider($definition)));
    }

    public function testUnTypeDePhaseInconnuEstBloquant(): void
    {
        $definition = new DefinitionTournoi(
            code: 'type',
            parametres: ['libelle' => 'Test'],
            phases: [new DefinitionPhase('x', 'pyramide', 1)],
            flux: [new Flux(Flux::SOURCE_INSCRIPTIONS, 'x', Selecteur::Tous)],
        );

        $validateur = new Validateur();

        self::assertFalse($validateur->ouvrable($validateur->valider($definition)));
    }

    public function testLeRapportEstLisible(): void
    {
        $validateur = new Validateur();
        $rapport    = $validateur->rapport($validateur->valider(Prereglages::tournoiSerie()));

        self::assertStringContainsString('ouvert', $rapport);
    }
}
