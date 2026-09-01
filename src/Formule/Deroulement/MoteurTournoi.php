<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Deroulement;

use InvalidArgumentException;
use RMCF\Tournois\Formule\Expression;
use RMCF\Tournois\Formule\Flux\Flux;
use RMCF\Tournois\Formule\Flux\MoteurFlux;
use RMCF\Tournois\Formule\Flux\ResultatFlux;
use RMCF\Tournois\Formule\Flux\Selecteur;
use RMCF\Tournois\Formule\Flux\SourceResultats;
use RMCF\Tournois\Formule\Generation\Generateurs;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\PhaseGeneree;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * L'orchestrateur : d'une definition et d'une liste d'inscrits, un tournoi.
 *
 * Il enchaine quatre operations, et aucune n'est propre a une formule
 * particuliere :
 *
 *   1. ACTIVATION (RG-21) — evaluer les conditions d'activation. Une
 *      phase inactive est ignoree et ses flux entrants sont rediriges
 *      vers la phase active suivante. Un tournoi unique s'adapte ainsi
 *      a 12 comme a 48 inscrits.
 *
 *   2. FLUX (RG-30 a RG-34) — determiner qui entre dans chaque phase.
 *
 *   3. BARRAGE INTERCALAIRE (RG-33) — si un flux produit plus de
 *      candidats que de places, inserer automatiquement une phase de
 *      barrage. C'est le mecanisme du MbN et celui des meilleurs
 *      deuxiemes des championnats du monde.
 *
 *   4. GENERATION — confier chaque phase au generateur de son type.
 *
 * Le moteur ne connait aucune formule nommee. Il ne sait pas ce qu'est
 * une consolante ni une double elimination : il applique des parametres
 * et des flux. C'est la condition pour que la matrice de couverture
 * C.12 soit vraie plutot que declarative.
 */
final class MoteurTournoi
{
    private readonly Generateurs $generateurs;

    public function __construct(
        private readonly SourceResultats $resultats,
        private readonly int $graine = 0,
    ) {
        $this->generateurs = new Generateurs($graine);
    }

    /**
     * Genere l'integralite du tournoi.
     *
     * @param Plateau $inscriptions plateau initial, deja ordonne par classement gele
     */
    public function generer(DefinitionTournoi $definition, Plateau $inscriptions): TournoiGenere
    {
        $avertissements = [];

        [$actives, $ignorees] = $this->activer($definition, $inscriptions, $avertissements);

        $flux = $this->rediriger($definition, $actives, $ignorees, $avertissements);

        $moteurFlux = new MoteurFlux($this->resultats, $this->graine);
        $resolus    = $moteurFlux->resoudre($flux, $inscriptions);

        $phases = [];

        foreach ($actives as $phase) {
            $resultat = $resolus[$phase->code] ?? null;

            // Une phase sans flux entrant explicite prend les inscrits :
            // c'est le cas de la premiere phase d'un tournoi simple.
            $entrants = $resultat?->entrants ?? $this->entrantsParDefaut($definition, $phase, $inscriptions);

            foreach ($resultat?->notes ?? [] as $note) {
                $avertissements[] = sprintf('[%s] %s', $phase->code, $note);
            }

            // RG-33 : surnombre -> phase de barrage intercalaire.
            if ($resultat !== null && $resultat->exigeBarrage()) {
                $barrage = $this->barrageIntercalaire($definition, $phase, $resultat);

                $phases[$barrage['code']] = $barrage['structure'];

                // Les places des futurs vainqueurs du barrage doivent
                // etre RESERVEES dans le tableau, sinon celui-ci se
                // genere trop petit et les qualifies du barrage n'ont
                // nulle part ou entrer. On les represente par des
                // entites provisoires, placees en fin de plateau : ce
                // sont par construction les moins bien classes des
                // qualifies, puisqu'ils sortent du surplus.
                $entrants = $entrants->fusionner(new Plateau(
                    $this->placesReservees($barrage['code'], $resultat->placesRestantes)
                ))->renumerote();

                $avertissements[] = sprintf(
                    '[%s] Barrage « %s » intercalé : %d entité(s) pour %d place(s).',
                    $phase->code,
                    $barrage['code'],
                    count($resultat->barrageRequis),
                    $resultat->placesRestantes
                );
            }

            $parametres = $definition->parametresPhase($phase);
            $generateur = $this->generateurs->pour($phase->type);

            $structure = $generateur->generer($phase->code, $entrants, $parametres);

            foreach ($structure->avertissements as $avertissement) {
                $avertissements[] = sprintf('[%s] %s', $phase->code, $avertissement);
            }

            $phases[$phase->code] = $structure;
        }

        return new TournoiGenere(
            tournoi: $definition->libelle(),
            phases: $phases,
            ignorees: $ignorees,
            avertissements: $avertissements,
            meta: [
                'nb_inscrits' => $inscriptions->effectif(),
                'graine'      => $this->graine,
            ],
        );
    }

    /**
     * Estime le volume sans rien generer (RG-91).
     *
     * L'estimation se fait phase par phase, en remontant les FLUX
     * ENTRANTS de chacune plutot qu'en propageant un effectif de proche
     * en proche. La difference n'est pas cosmetique : avec deux flux
     * sortants d'une meme poule — les qualifies au tableau, le reste en
     * consolante — une propagation naive compte deux fois le meme
     * plateau et surestime la soiree de moitie.
     *
     * Une estimation fausse est pire qu'absente : un organisateur qui
     * constate une fois que « 82 parties » en valait 58 n'ouvrira plus
     * jamais l'ecran de verification.
     *
     * @return array{parties:int,par_phase:array<string,int>,par_phase_effectif:array<string,int>,duree_minutes:int}
     */
    public function estimer(DefinitionTournoi $definition, int $nbInscrits): array
    {
        $parPhase   = [];
        $effectifs  = [Flux::SOURCE_INSCRIPTIONS => $nbInscrits];
        $total      = 0;

        foreach ($definition->phasesOrdonnees() as $phase) {
            if (! $this->phaseActive($phase, $definition, $nbInscrits, $nbInscrits)) {
                continue;
            }

            $parametres = $definition->parametresPhase($phase);
            $effectif   = $this->effectifEntrant($definition, $phase->code, $effectifs, $nbInscrits);

            $effectifs[$phase->code] = $effectif;

            $volume = $this->generateurs->pour($phase->type)->volume($effectif, $parametres);

            $parPhase[$phase->code] = $volume;
            $total                 += $volume;

            // RG-33 : le barrage intercalaire n'est declare nulle part,
            // il apparait a la generation. L'estimation doit malgre tout
            // le compter, sinon elle sous-estime precisement les soirees
            // les plus chargees — celles ou l'estimation sert.
            $barrage = $this->volumeBarrageIntercalaire($definition, $phase->code, $effectifs, $nbInscrits);

            if ($barrage > 0) {
                $parPhase[$phase->code . '_barrage'] = $barrage;
                $total                              += $barrage;
            }
        }

        $duree = $definition->parametres()->entier('duree_estimee_partie');

        if ($duree === null || $duree <= 0) {
            $duree = 15;
        }

        return [
            'parties'            => $total,
            'par_phase'          => $parPhase,
            'par_phase_effectif' => array_diff_key($effectifs, [Flux::SOURCE_INSCRIPTIONS => 0]),
            'duree_minutes'      => $total * $duree,
        ];
    }

    /**
     * Combien d'entites entrent dans une phase, d'apres ses flux.
     *
     * @param array<string,int> $effectifs effectifs deja calcules
     */
    private function effectifEntrant(
        DefinitionTournoi $definition,
        string $phase,
        array $effectifs,
        int $nbInscrits,
    ): int {
        $entrants = $definition->fluxVers($phase);

        if ($entrants === []) {
            return $nbInscrits;
        }

        $total = 0;

        foreach ($entrants as $flux) {
            $source = $effectifs[$flux->phaseSource] ?? $nbInscrits;
            $phaseSource = $definition->phase($flux->phaseSource);

            $apport = $this->apportDuFlux($definition, $flux, $source, $phaseSource);

            // RG-32 — `non_qualifies` ramasse ce que les autres flux de
            // la meme source ont laisse, jamais le plateau entier.
            if ($flux->selecteur === Selecteur::NonQualifies) {
                $pris = 0;

                foreach ($definition->fluxDepuis($flux->phaseSource) as $autre) {
                    if ($autre->selecteur === Selecteur::NonQualifies) {
                        continue;
                    }

                    $pris += $this->apportDuFlux($definition, $autre, $source, $phaseSource);
                }

                $apport = max(0, $source - $pris);
            }

            if ($flux->capaciteMax !== null) {
                $apport = min($apport, $flux->capaciteMax);
            }

            $total += $apport;
        }

        return max(0, $total);
    }

    /**
     * Volume du barrage que RG-33 intercalera devant cette phase.
     *
     * Le mecanisme : `surplus` candidats de trop font descendre autant
     * de qualifies d'office dans le barrage, soit `2 x surplus`
     * barragistes pour `surplus` places. Chaque partie eliminant une
     * entite, le barrage compte exactement `surplus` parties.
     *
     * @param array<string,int> $effectifs
     */
    private function volumeBarrageIntercalaire(
        DefinitionTournoi $definition,
        string $phase,
        array $effectifs,
        int $nbInscrits,
    ): int {
        $total = 0;

        foreach ($definition->fluxVers($phase) as $flux) {
            if ($flux->capaciteMax === null || $flux->siSurnombre !== Flux::SURNOMBRE_BARRAGE) {
                continue;
            }

            $source      = $effectifs[$flux->phaseSource] ?? $nbInscrits;
            $phaseSource = $definition->phase($flux->phaseSource);
            $candidats   = $this->apportDuFlux($definition, $flux, $source, $phaseSource);

            $surplus = $candidats - $flux->capaciteMax;

            if ($surplus > 0) {
                $total += $surplus;
            }
        }

        return $total;
    }

    private function apportDuFlux(
        DefinitionTournoi $definition,
        Flux $flux,
        int $effectifSource,
        ?DefinitionPhase $phaseSource,
    ): int {
        $nbGroupes = 1;

        if ($phaseSource !== null && $phaseSource->type === 'poules') {
            $parametres = $definition->parametresPhase($phaseSource);
            $taille     = $parametres->estAuto('taille_groupe')
                ? 4
                : ($parametres->entier('taille_groupe', 4) ?? 4);

            $nbGroupes = max(1, (int) round($effectifSource / max(1, $taille)));
        }

        return match ($flux->selecteur) {
            Selecteur::Tous, Selecteur::Repeches => $effectifSource,
            Selecteur::PlaceExacte               => $nbGroupes,
            Selecteur::PlacesDeA                 => (function () use ($flux, $nbGroupes): int {
                [$de, $a] = $flux->parametreIntervalle();

                return $nbGroupes * max(1, $a - $de + 1);
            })(),
            Selecteur::Montants, Selecteur::Descendants => $nbGroupes * $flux->parametreEntier(),
            Selecteur::TopNGlobal, Selecteur::MeilleursNiemes => $flux->parametreEntier(),
            // La moitie d'un tableau sort au premier tour ; au-dela, un
            // quart, puis un huitieme.
            Selecteur::PerdantsTour, Selecteur::VainqueursTour
                => max(1, intdiv($effectifSource, 2 ** max(1, $flux->parametreEntier()))),
            Selecteur::EliminesAvecNDefaites => max(0, $effectifSource - 1),
            Selecteur::Manuel                => count($flux->designes),
            Selecteur::NonQualifies          => $effectifSource,
        };
    }

    // -----------------------------------------------------------------
    // Activation
    // -----------------------------------------------------------------

    /**
     * @param  list<string> $avertissements
     * @return array{0:list<DefinitionPhase>,1:array<string,string>}
     */
    private function activer(
        DefinitionTournoi $definition,
        Plateau $inscriptions,
        array &$avertissements,
    ): array {
        $actives  = [];
        $ignorees = [];

        foreach ($definition->phasesOrdonnees() as $phase) {
            if ($this->phaseActive($phase, $definition, $inscriptions->effectif(), $inscriptions->effectif())) {
                $actives[] = $phase;
                continue;
            }

            $ignorees[$phase->code] = sprintf(
                'condition « %s » non satisfaite',
                $phase->conditionActivation
            );
        }

        if ($actives === []) {
            $avertissements[] = 'Aucune phase active : le tournoi ne produirait aucune partie.';
        }

        return [$actives, $ignorees];
    }

    private function phaseActive(
        DefinitionPhase $phase,
        DefinitionTournoi $definition,
        int $nbInscrits,
        int $effectifPhase,
    ): bool {
        $condition = trim($phase->conditionActivation);

        if ($condition === '') {
            return true;
        }

        $variables = [
            'nb_inscrits'    => $nbInscrits,
            'nb_entrants'    => $effectifPhase,
            'nb_qualifies'   => $definition->parametresPhase($phase)->entier('nb_qualifies', 2) ?? 2,
            'taille_tableau' => $definition->parametresPhase($phase)->entier('taille_tableau', 0) ?? 0,
        ];

        try {
            return Expression::evaluerCondition($condition, $variables);
        } catch (InvalidArgumentException) {
            // Une condition illisible ne doit pas faire disparaitre une
            // phase en silence : dans le doute, la phase est active et
            // le validateur signalera l'expression.
            return true;
        }
    }

    /**
     * Redirige les flux des phases ignorees (RG-21).
     *
     * Un flux qui pointait vers une phase inactive est reporte sur la
     * phase active suivante ; un flux qui en PARTAIT est reporte sur la
     * source de la phase ignoree, de facon a ne jamais couper la chaine.
     *
     * @param  list<DefinitionPhase>  $actives
     * @param  array<string,string>   $ignorees
     * @param  list<string>           $avertissements
     * @return list<Flux>
     */
    private function rediriger(
        DefinitionTournoi $definition,
        array $actives,
        array $ignorees,
        array &$avertissements,
    ): array {
        if ($ignorees === []) {
            return $definition->flux;
        }

        $codesActifs = array_map(
            static fn (DefinitionPhase $p): string => $p->code,
            $actives
        );

        $flux = [];

        foreach ($definition->flux as $unFlux) {
            $source = $unFlux->phaseSource;
            $cible  = $unFlux->phaseCible;

            // La source a disparu : on remonte a la source de sa propre
            // alimentation, jusqu'a retomber sur une phase active.
            $garde = 0;

            while (
                $source !== Flux::SOURCE_INSCRIPTIONS
                && ! in_array($source, $codesActifs, true)
                && $garde++ < 20
            ) {
                $amont  = $definition->fluxVers($source);
                $source = $amont[0]->phaseSource ?? Flux::SOURCE_INSCRIPTIONS;
            }

            // La cible a disparu : le flux est abandonne, ses entites
            // seront reprises par le flux `non_qualifies` suivant.
            if (! in_array($cible, $codesActifs, true)) {
                $avertissements[] = sprintf(
                    'Flux « %s » abandonné : la phase cible est inactive.',
                    $unFlux->description()
                );
                continue;
            }

            $flux[] = $source === $unFlux->phaseSource
                ? $unFlux
                : new Flux(
                    $source,
                    $cible,
                    $unFlux->selecteur,
                    $unFlux->parametre,
                    $unFlux->ordre,
                    $unFlux->tourEntreeCible,
                    $unFlux->modePlacement,
                    $unFlux->regleOrdre,
                    $unFlux->capaciteMax,
                    $unFlux->siSurnombre,
                    $unFlux->siSousNombre,
                    $unFlux->designes,
                );
        }

        return $flux;
    }

    // -----------------------------------------------------------------
    // Barrage intercalaire
    // -----------------------------------------------------------------

    /**
     * Cree la phase de barrage exigee par un surnombre (RG-33).
     *
     * @return array{code:string,structure:PhaseGeneree}
     */
    private function barrageIntercalaire(
        DefinitionTournoi $definition,
        DefinitionPhase $cible,
        ResultatFlux $resultat,
    ): array {
        $code = $cible->code . '_barrage';

        $entites = [];

        foreach ($resultat->barrageRequis as $ref) {
            $entites[] = $this->resultats->entite($ref) ?? new Entite($ref, $ref);
        }

        $parametres = $definition->parametresPhase($cible)->avec([
            'type_phase'     => 'barrage',
            'moment'         => 'apres_poules',
            'objet'          => 'acces_tableau',
            'format_barrage' => count($entites) === 2 ? 'match_unique' : 'elimination_directe',
            'nb_qualifies'   => $resultat->placesRestantes,
        ]);

        $structure = $this->generateurs
            ->pour('barrage')
            ->generer($code, (new Plateau($entites))->parRang(), $parametres);

        return ['code' => $code, 'structure' => $structure];
    }

    /**
     * Entites provisoires tenant les places d'un barrage non encore joue.
     *
     * Elles portent une reference reconnaissable (`code#n`) pour que la
     * couche de persistance sache qu'il s'agit d'une place a pourvoir et
     * non d'une inscription. Une fois le barrage joue, le moteur
     * regenere et ces entites sont remplacees par les vrais qualifies :
     * le tableau garde exactement la meme forme, ce qui permet de
     * l'imprimer des l'ouverture, places de barrage comprises.
     *
     * @return list<Entite>
     */
    private function placesReservees(string $barrage, int $combien): array
    {
        $places = [];

        for ($i = 1; $i <= max(0, $combien); $i++) {
            $places[] = new Entite(
                ref: $barrage . '#' . $i,
                libelle: 'Qualifié du barrage ' . $i,
            );
        }

        return $places;
    }

    // -----------------------------------------------------------------
    // Divers
    // -----------------------------------------------------------------

    /**
     * Entrants d'une phase sans flux entrant resolu.
     *
     * RG-30 exige qu'une phase autre que la premiere ait au moins un
     * flux entrant : ce cas ne devrait donc concerner que la premiere.
     * On ne leve pas d'erreur ici — c'est le role du validateur, qui
     * s'execute avant l'ouverture, pas au milieu d'une soiree.
     */
    private function entrantsParDefaut(
        DefinitionTournoi $definition,
        DefinitionPhase $phase,
        Plateau $inscriptions,
    ): Plateau {
        $premiere = $definition->phasesOrdonnees()[0] ?? null;

        return $premiere !== null && $premiere->code === $phase->code
            ? $inscriptions
            : Plateau::vide();
    }

}
