<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Flux;

use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * Resolution des flux de qualification (C.5, §9.5).
 *
 * Le moteur repond a une seule question, pour chaque phase cible : QUI
 * entre, et DANS QUEL ORDRE. Il applique quatre regles, et l'ordre dans
 * lequel il les applique est ce qui rend le resultat correct :
 *
 *   RG-31  une entite ne peut etre selectionnee que par un seul flux ;
 *          en cas de conflit, le flux de plus petit `ordre` gagne.
 *   RG-32  `non_qualifies` est evalue APRES tous les autres flux issus
 *          de la meme phase source. C'est ce qui permet d'ecrire « les 2
 *          premiers au tableau, tout le reste en consolante » sans
 *          enumerer les places restantes.
 *   RG-33  au-dela de `capacite_max`, on applique `si_surnombre`. Le
 *          mode `barrage` ne tronque pas : il remonte les barragistes.
 *   RG-34  le mode de placement `croise` ecarte les entites de meme
 *          origine, dans la mesure du possible.
 *
 * Le moteur ne cree aucune phase et n'ecrit rien. Il calcule des
 * plateaux ; c'est le moteur de tournoi qui en fait quelque chose.
 */
final class MoteurFlux
{
    public function __construct(
        private readonly SourceResultats $resultats,
        private readonly int $graine = 0,
    ) {
    }

    /**
     * Resout tous les flux et rend un resultat par phase cible.
     *
     * @param  list<Flux> $flux
     * @param  Plateau    $inscriptions plateau initial, source `inscriptions`
     * @return array<string,ResultatFlux>
     */
    public function resoudre(array $flux, Plateau $inscriptions): array
    {
        $flux = $this->ordonner($flux);

        /** @var array<string,array<string,bool>> phase source => ref => prise */
        $prises = [];

        /** @var array<string,list<Entite>> phase cible => entites */
        $entrants = [];

        /** @var array<string,list<string>> */
        $notes = [];

        /** @var array<string,list<string>> */
        $refusees = [];

        /** @var array<string,list<string>> */
        $barrages = [];

        /** @var array<string,int> */
        $places = [];

        foreach ($flux as $unFlux) {
            $selection = $this->selectionner($unFlux, $inscriptions, $prises[$unFlux->phaseSource] ?? []);

            if ($selection === []) {
                $notes[$unFlux->phaseCible][] = sprintf(
                    'Flux « %s » : aucune entite selectionnee.',
                    $unFlux->description()
                );
                continue;
            }

            foreach ($selection as $entite) {
                $prises[$unFlux->phaseSource][$entite->ref] = true;
            }

            $selection = $this->placer($selection, $unFlux);

            // RG-33 — capacite.
            if ($unFlux->capaciteMax !== null && count($selection) > $unFlux->capaciteMax) {
                $capacite  = $unFlux->capaciteMax;
                $retenus   = array_slice($selection, 0, $capacite);
                $surplus   = array_slice($selection, $capacite);

                if ($unFlux->siSurnombre === Flux::SURNOMBRE_BARRAGE) {
                    // Les derniers qualifies d'office cedent leur place a
                    // un barrage : ce sont eux et le surplus qui jouent.
                    $aDepartager = [...array_slice($retenus, -count($surplus)), ...$surplus];
                    $retenus     = array_slice($retenus, 0, max(0, $capacite - count($surplus)));

                    $barrages[$unFlux->phaseCible] = array_map(
                        static fn (Entite $e): string => $e->ref,
                        $aDepartager
                    );
                    $places[$unFlux->phaseCible] = $capacite - count($retenus);

                    $notes[$unFlux->phaseCible][] = sprintf(
                        '%d candidat(s) pour %d place(s) : barrage requis entre %d entite(s).',
                        count($selection),
                        $capacite,
                        count($aDepartager)
                    );
                } elseif ($unFlux->siSurnombre === Flux::SURNOMBRE_TRONQUER) {
                    $refusees[$unFlux->phaseCible] = array_map(
                        static fn (Entite $e): string => $e->ref,
                        $surplus
                    );

                    $notes[$unFlux->phaseCible][] = sprintf(
                        '%d entite(s) ecartee(s) : capacite de %d atteinte.',
                        count($surplus),
                        $capacite
                    );
                } else {
                    $retenus = $selection;

                    $notes[$unFlux->phaseCible][] = sprintf(
                        'Cible elargie a %d places pour absorber le surnombre.',
                        count($selection)
                    );
                }

                $selection = $retenus;
            }

            $entrants[$unFlux->phaseCible] = [
                ...($entrants[$unFlux->phaseCible] ?? []),
                ...$selection,
            ];
        }

        $resultats = [];

        foreach ($entrants as $cible => $entites) {
            $resultats[$cible] = new ResultatFlux(
                phaseCible: $cible,
                entrants: (new Plateau($this->dedoublonner($entites)))->renumerote(),
                refusees: $refusees[$cible] ?? [],
                barrageRequis: $barrages[$cible] ?? [],
                placesRestantes: $places[$cible] ?? 0,
                notes: $notes[$cible] ?? [],
            );
        }

        // Phases citees en cible mais qui n'ont recu personne.
        foreach ($flux as $unFlux) {
            if (! isset($resultats[$unFlux->phaseCible])) {
                $resultats[$unFlux->phaseCible] = new ResultatFlux(
                    phaseCible: $unFlux->phaseCible,
                    entrants: Plateau::vide(),
                    notes: $notes[$unFlux->phaseCible] ?? ['Aucun entrant.'],
                );
            }
        }

        return $resultats;
    }

    /**
     * Trie les flux : par ordre, puis `non_qualifies` en dernier (RG-32).
     *
     * @param  list<Flux> $flux
     * @return list<Flux>
     */
    private function ordonner(array $flux): array
    {
        usort($flux, static function (Flux $a, Flux $b): int {
            $ra = $a->selecteur === Selecteur::NonQualifies ? 1 : 0;
            $rb = $b->selecteur === Selecteur::NonQualifies ? 1 : 0;

            return [$ra, $a->ordre] <=> [$rb, $b->ordre];
        });

        return $flux;
    }

    /**
     * Applique le selecteur.
     *
     * @param  array<string,bool> $dejaPrises
     * @return list<Entite>
     */
    private function selectionner(Flux $flux, Plateau $inscriptions, array $dejaPrises): array
    {
        if ($flux->depuisInscriptions()) {
            return $inscriptions->entites();
        }

        $phase = $flux->phaseSource;

        if ($flux->selecteur->exigeCloture() && ! $this->resultats->estClose($phase)) {
            return [];
        }

        $refs = match ($flux->selecteur) {
            Selecteur::PlaceExacte     => $this->placesDeChaqueGroupe($phase, ...$this->intervalleUnique($flux)),
            Selecteur::PlacesDeA       => $this->placesDeChaqueGroupe($phase, ...$flux->parametreIntervalle()),
            Selecteur::MeilleursNiemes => $this->meilleursNiemes($phase, $flux),
            Selecteur::TopNGlobal      => array_slice(
                $this->resultats->classementGlobal($phase),
                0,
                $flux->parametreEntier()
            ),
            Selecteur::PerdantsTour    => $this->resultats->perdantsTour($phase, $flux->parametreEntier()),
            Selecteur::VainqueursTour  => $this->resultats->vainqueursTour($phase, $flux->parametreEntier()),
            Selecteur::EliminesAvecNDefaites => $this->parDefaites($phase, $flux->parametreEntier()),
            Selecteur::Montants        => $this->placesDeChaqueGroupe($phase, 1, $flux->parametreEntier()),
            Selecteur::Descendants     => $this->derniersDeChaqueGroupe($phase, $flux->parametreEntier()),
            Selecteur::Repeches, Selecteur::NonQualifies, Selecteur::Tous
                                       => $this->resultats->classementGlobal($phase),
            Selecteur::Manuel          => $flux->designes,
        };

        // RG-31 : ce qu'un flux anterieur a deja pris n'est plus disponible.
        $refs = array_values(array_filter(
            $refs,
            static fn (string $ref): bool => ! isset($dejaPrises[$ref])
        ));

        $entites = [];

        foreach ($refs as $ref) {
            $entites[] = $this->resultats->entite($ref) ?? new Entite($ref, $ref);
        }

        return $entites;
    }

    /** @return array{0:int,1:int} */
    private function intervalleUnique(Flux $flux): array
    {
        $k = $flux->parametreEntier();

        return [$k, $k];
    }

    /**
     * Les places `$de` a `$a` de chaque groupe, place par place.
     *
     * L'ordre de sortie est celui du classement croise : tous les
     * premiers, puis tous les deuxiemes. C'est ce qui donne un plateau
     * exploitable directement par le placement `croise`.
     *
     * @return list<string>
     */
    private function placesDeChaqueGroupe(string $phase, int $de, int $a): array
    {
        $refs = [];

        for ($place = $de; $place <= $a; $place++) {
            foreach ($this->resultats->groupes($phase) as $groupe) {
                $classement = $this->resultats->classementGroupe($phase, $groupe);

                if (isset($classement[$place - 1])) {
                    $refs[] = $classement[$place - 1];
                }
            }
        }

        return $refs;
    }

    /** @return list<string> */
    private function derniersDeChaqueGroupe(string $phase, int $combien): array
    {
        $refs = [];

        foreach ($this->resultats->groupes($phase) as $groupe) {
            $classement = $this->resultats->classementGroupe($phase, $groupe);
            $refs       = [...$refs, ...array_slice($classement, -max(1, $combien))];
        }

        return $refs;
    }

    /**
     * Les n meilleurs k-iemes, toutes poules confondues.
     *
     * Le classement inter-groupes fait foi : c'est lui qui compare des
     * joueurs qui ne se sont jamais rencontres, avec la cascade dediee
     * (§7.6). On ne compare jamais les places brutes.
     *
     * @return list<string>
     */
    private function meilleursNiemes(string $phase, Flux $flux): array
    {
        $place  = 2;
        $combien = $flux->parametreEntier();

        if (is_string($flux->parametre) && str_contains($flux->parametre, ':')) {
            [$place, $combien] = array_map('intval', explode(':', $flux->parametre, 2));
        }

        $candidats = $this->placesDeChaqueGroupe($phase, $place, $place);
        $global    = $this->resultats->classementGlobal($phase);
        $ordonnes  = [];

        foreach ($global as $ref) {
            if (in_array($ref, $candidats, true)) {
                $ordonnes[] = $ref;
            }
        }

        // Ce que le classement global ignore vient en fin de liste.
        foreach ($candidats as $ref) {
            if (! in_array($ref, $ordonnes, true)) {
                $ordonnes[] = $ref;
            }
        }

        return array_slice($ordonnes, 0, max(0, $combien));
    }

    /** @return list<string> */
    private function parDefaites(string $phase, int $seuil): array
    {
        $refs = [];

        foreach ($this->resultats->classementGlobal($phase) as $ref) {
            if ($this->resultats->defaites($phase, $ref) >= $seuil) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * Ordonne les entrants selon `mode_placement` (RG-34).
     *
     * @param  list<Entite> $entites
     * @return list<Entite>
     */
    private function placer(array $entites, Flux $flux): array
    {
        return match ($flux->modePlacement) {
            'tetes_de_serie' => $this->parRang($entites),
            'miroir'         => array_reverse($entites),
            'tirage'         => (new Plateau($entites))->melanger($this->graine)->entites(),
            'serpentin'      => $this->serpentin($entites),
            'manuel'         => $entites,
            default          => $this->croise($entites),
        };
    }

    /**
     * @param  list<Entite> $entites
     * @return list<Entite>
     */
    private function parRang(array $entites): array
    {
        return (new Plateau($entites))->parRang()->entites();
    }

    /**
     * Placement croise : les entites de meme origine s'eloignent.
     *
     * On alterne le sens de parcours des groupes d'une place a l'autre.
     * Le 1er de A et le 2e de A se retrouvent ainsi aux deux extremites
     * du plateau, donc dans des moities opposees du tableau — ce que
     * demande RG-34.
     *
     * @param  list<Entite> $entites
     * @return list<Entite>
     */
    private function croise(array $entites): array
    {
        /** @var array<string,list<Entite>> $parOrigine */
        $parOrigine = [];

        foreach ($entites as $entite) {
            $parOrigine[$entite->origine ?? ''][] = $entite;
        }

        if (count($parOrigine) <= 1) {
            return $entites;
        }

        $origines  = array_keys($parOrigine);
        $profondeur = max(array_map('count', $parOrigine));
        $ordonnes  = [];

        for ($place = 0; $place < $profondeur; $place++) {
            $parcours = $place % 2 === 0 ? $origines : array_reverse($origines);

            foreach ($parcours as $origine) {
                if (isset($parOrigine[$origine][$place])) {
                    $ordonnes[] = $parOrigine[$origine][$place];
                }
            }
        }

        return $ordonnes;
    }

    /**
     * @param  list<Entite> $entites
     * @return list<Entite>
     */
    private function serpentin(array $entites): array
    {
        return $this->croise($this->parRang($entites));
    }

    /**
     * @param  list<Entite> $entites
     * @return list<Entite>
     */
    private function dedoublonner(array $entites): array
    {
        $vues     = [];
        $resultat = [];

        foreach ($entites as $entite) {
            if (isset($vues[$entite->ref])) {
                continue;
            }

            $vues[$entite->ref] = true;
            $resultat[]         = $entite;
        }

        return $resultat;
    }
}
