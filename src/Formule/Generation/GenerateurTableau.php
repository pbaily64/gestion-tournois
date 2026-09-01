<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Generation;

use RMCF\Tournois\Formule\Parametres;
use RMCF\Tournois\Formule\Structure\Appariement;
use RMCF\Tournois\Formule\Structure\Emplacement;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\PhaseGeneree;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * Briques 2, 3, 4 et 5 — la famille des tableaux (§3.2 a §3.5).
 *
 * Le document etablit que consolante, double elimination et classement
 * integral ne sont pas quatre formules mais UNE SEULE, parametree par
 * une seule variable : combien de defaites avant de sortir du classement.
 *
 *     defaites_tolerees = 1       elimination directe
 *     defaites_tolerees = 2       double elimination (branche des perdants)
 *     defaites_tolerees = infini  classement integral, 1 a n sans ex aequo
 *     defaites_tolerees = N       vies multiples — routage par flux (RG-22)
 *
 * Ce generateur applique donc le routage, sans jamais connaitre les mots
 * « consolante » ou « repechage ». Une consolante n'est pas un cas
 * particulier ici : c'est une phase `tableau` distincte, alimentee par un
 * flux `perdants_tour 1` (C.5). Une phase, un flux, aucun code special.
 *
 * Le cas `defaites_tolerees = N` avec N >= 3 est volontairement laisse au
 * moteur de flux : coder une topologie figee a N branches serait
 * complexe, fragile, et contredirait la recommandation du §3.4 —
 * modeliser les vies comme un compteur porte par l'inscrit.
 */
final class GenerateurTableau implements Generateur
{
    public function __construct(
        private readonly string $type = 'tableau',
        private readonly int $graine = 0,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function generer(string $phase, Plateau $entrants, Parametres $p): PhaseGeneree
    {
        $avertissements = [];
        $effectif       = $entrants->effectif();

        if ($effectif < 2) {
            return new PhaseGeneree(
                $phase,
                $this->type,
                [],
                [],
                [],
                ['Moins de deux entrants : aucun tableau a produire.']
            );
        }

        $taille = $this->taille($effectif, $p, $avertissements);
        $ordre  = $this->ordonner($entrants, $p, $taille, $avertissements);

        $defaites = $this->defaitesTolerees($p);

        return match (true) {
            $defaites === PHP_INT_MAX => $this->classementIntegral($phase, $ordre, $taille, $p, $avertissements),
            $defaites >= 2            => $this->doubleElimination($phase, $ordre, $taille, $p, $avertissements),
            default                   => $this->eliminationSimple($phase, $ordre, $taille, $p, $avertissements),
        };
    }

    public function volume(int $effectif, Parametres $p): int
    {
        if ($effectif < 2) {
            return 0;
        }

        $taille   = PlacementTableau::taillePuissanceDeDeux($effectif);
        $defaites = $this->defaitesTolerees($p);

        if ($defaites === PHP_INT_MAX) {
            return intdiv($taille, 2) * PlacementTableau::nombreTours($taille);
        }

        if ($defaites >= 2) {
            // 2n-2 parties, plus la grande finale de reset le cas echeant.
            return 2 * $effectif - 2 + ($p->booleen('grande_finale_reset') ? 1 : 0);
        }

        return ($effectif - 1) + ($p->booleen('petite_finale') ? 1 : 0);
    }

    // -----------------------------------------------------------------
    // Dimensionnement et placement
    // -----------------------------------------------------------------

    /** @param list<string> $avertissements */
    private function taille(int $effectif, Parametres $p, array &$avertissements): int
    {
        // RG-20 : `auto` = plus petite puissance de 2 >= nombre de qualifies.
        if ($p->estAuto('taille_tableau') || ! $p->estRenseigne('taille_tableau')) {
            return PlacementTableau::taillePuissanceDeDeux($effectif);
        }

        $taille = $p->entier('taille_tableau') ?? PlacementTableau::taillePuissanceDeDeux($effectif);

        if ($taille < $effectif) {
            $avertissements[] = sprintf(
                'Tableau de %d places pour %d entrants : %d entite(s) sans place. '
                . 'Prevoir un barrage (si_surnombre) ou elargir le tableau.',
                $taille,
                $effectif,
                $effectif - $taille
            );

            return $taille;
        }

        $exempts = $taille - $effectif;

        if ($exempts > intdiv($taille, 2)) {
            $avertissements[] = sprintf(
                'Tableau de %d places pour %d entrants : %d exempts, soit plus de la moitie du premier tour.',
                $taille,
                $effectif,
                $exempts
            );
        }

        return $taille;
    }

    /**
     * Ordonne les entrants avant placement, puis applique la separation.
     *
     * @param  list<string> $avertissements
     * @return list<Entite>
     */
    private function ordonner(Plateau $entrants, Parametres $p, int $taille, array &$avertissements): array
    {
        $mode = $p->texte('placement_qualifies', 'croise');

        $plateau = match ($mode) {
            'tirage_integral' => $entrants->melanger($this->graine),
            'manuel'          => $entrants,
            default           => $entrants->parRang(),
        };

        $entites = $plateau->entites();
        $zone    = $p->texte('separer_meme_poule', 'non');

        if ($zone !== 'non' && $zone !== '') {
            $entites = $this->separer($entites, $taille, $zone, $avertissements);
        }

        return $entites;
    }

    /**
     * Ecarte les entites de meme origine dans des zones distinctes (RG-34).
     *
     * On procede par echanges de tetes de serie voisines : echanger le
     * seed 5 et le seed 6 change de moitie sans changer de niveau, alors
     * qu'echanger le seed 1 et le seed 8 fausserait tout le tableau.
     *
     * @param  list<Entite> $entites
     * @param  list<string> $avertissements
     * @return list<Entite>
     */
    private function separer(array $entites, int $taille, string $zone, array &$avertissements): array
    {
        $ordreSeeds = PlacementTableau::ordreSeeds($taille);
        $nb         = count($entites);
        $conflits   = 0;

        // position de tableau de chaque seed (1-base) => index de position
        $positionDuSeed = [];

        foreach ($ordreSeeds as $position => $seed) {
            $positionDuSeed[$seed] = $position;
        }

        for ($seed = 1; $seed <= $nb; $seed++) {
            $entite = $entites[$seed - 1];

            if ($entite->origine === null) {
                continue;
            }

            $maZone = PlacementTableau::zone($positionDuSeed[$seed] ?? 0, $taille, $zone);
            $gene   = false;

            for ($autre = 1; $autre < $seed; $autre++) {
                if ($entites[$autre - 1]->origine !== $entite->origine) {
                    continue;
                }

                if (PlacementTableau::zone($positionDuSeed[$autre] ?? 0, $taille, $zone) === $maZone) {
                    $gene = true;
                    break;
                }
            }

            if (! $gene) {
                continue;
            }

            $echange = $this->voisinLibre($entites, $seed, $taille, $zone, $positionDuSeed, $nb);

            if ($echange === null) {
                $conflits++;
                continue;
            }

            [$entites[$seed - 1], $entites[$echange - 1]] = [$entites[$echange - 1], $entites[$seed - 1]];
        }

        if ($conflits > 0) {
            $avertissements[] = sprintf(
                '%d qualifie(s) n\'ont pas pu etre separes de leur poule d\'origine dans la zone « %s ».',
                $conflits,
                $zone
            );
        }

        return $entites;
    }

    /**
     * @param  list<Entite>       $entites
     * @param  array<int,int>     $positionDuSeed
     */
    private function voisinLibre(
        array $entites,
        int $seed,
        int $taille,
        string $zone,
        array $positionDuSeed,
        int $nb,
    ): ?int {
        $entite = $entites[$seed - 1];

        // On ne s'ecarte que de quelques rangs : au-dela, on trahirait
        // le classement plus gravement que le conflit qu'on repare.
        for ($delta = 1; $delta <= 3; $delta++) {
            foreach ([$seed + $delta, $seed - $delta] as $candidat) {
                if ($candidat < 1 || $candidat > $nb || $candidat === $seed) {
                    continue;
                }

                $zoneCandidat = PlacementTableau::zone($positionDuSeed[$candidat] ?? 0, $taille, $zone);
                $collision    = false;

                foreach ($entites as $i => $autre) {
                    $s = $i + 1;

                    if ($s === $seed || $s === $candidat || $autre->origine !== $entite->origine) {
                        continue;
                    }

                    if (PlacementTableau::zone($positionDuSeed[$s] ?? 0, $taille, $zone) === $zoneCandidat) {
                        $collision = true;
                        break;
                    }
                }

                if (! $collision) {
                    return $candidat;
                }
            }
        }

        return null;
    }

    private function defaitesTolerees(Parametres $p): int
    {
        $valeur = $p->valeur('defaites_tolerees');

        if ($valeur === 'infini' || $valeur === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        if ($this->type === 'classement_integral') {
            return PHP_INT_MAX;
        }

        return max(1, (int) ($valeur ?? 1));
    }

    // -----------------------------------------------------------------
    // Topologie 1 — elimination directe
    // -----------------------------------------------------------------

    /**
     * @param  list<Entite> $entites
     * @param  list<string> $avertissements
     */
    private function eliminationSimple(
        string $phase,
        array $entites,
        int $taille,
        Parametres $p,
        array $avertissements,
    ): PhaseGeneree {
        $appariements = [];
        $tours        = [];
        $nbTours      = PlacementTableau::nombreTours($taille);
        $positions    = PlacementTableau::positions(count($entites), $taille);

        // --- premier tour ---------------------------------------------
        $precedent = [];
        $ordre     = 1;

        for ($i = 0; $i < $taille; $i += 2) {
            $a = $positions[$i] !== null
                ? Emplacement::entite($entites[$positions[$i]])
                : Emplacement::vide();
            $b = $positions[$i + 1] !== null
                ? Emplacement::entite($entites[$positions[$i + 1]])
                : Emplacement::vide();

            $id = sprintf('%s-T1-%02d', $phase, $ordre);

            $appariements[] = new Appariement(
                id: $id,
                phase: $phase,
                a: $a,
                b: $b,
                tour: 1,
                ordre: $ordre,
                groupe: 'principal',
                role: Appariement::ROLE_TABLEAU,
                libelle: PlacementTableau::libelleTour($taille) . ' — ' . $ordre,
            );

            $precedent[] = $id;
            $ordre++;
        }

        $tours[1] = PlacementTableau::libelleTour($taille);

        // --- tours suivants -------------------------------------------
        for ($tour = 2; $tour <= $nbTours; $tour++) {
            $restants  = intdiv($taille, 2 ** ($tour - 1));
            $tours[$tour] = PlacementTableau::libelleTour($restants);
            $courant   = [];
            $ordre     = 1;

            for ($i = 0; $i < count($precedent); $i += 2) {
                $id = sprintf('%s-T%d-%02d', $phase, $tour, $ordre);

                $appariements[] = new Appariement(
                    id: $id,
                    phase: $phase,
                    a: Emplacement::vainqueurDe($precedent[$i]),
                    b: Emplacement::vainqueurDe($precedent[$i + 1]),
                    tour: $tour,
                    ordre: $ordre,
                    groupe: 'principal',
                    role: Appariement::ROLE_TABLEAU,
                    libelle: $tours[$tour] . ($restants > 2 ? ' — ' . $ordre : ''),
                );

                $courant[] = $id;
                $ordre++;
            }

            $precedent = $courant;
        }

        // --- petite finale --------------------------------------------
        if ($p->booleen('petite_finale') && $nbTours >= 2) {
            $demies = array_values(array_filter(
                $appariements,
                static fn (Appariement $a): bool => $a->tour === $nbTours - 1
            ));

            if (count($demies) === 2) {
                $appariements[] = new Appariement(
                    id: $phase . '-PF',
                    phase: $phase,
                    a: Emplacement::perdantDe($demies[0]->id),
                    b: Emplacement::perdantDe($demies[1]->id),
                    tour: $nbTours,
                    ordre: 2,
                    groupe: 'principal',
                    role: Appariement::ROLE_PETITE_FINALE,
                    libelle: 'Match pour la 3e place',
                    enjeu: 'Places 3 et 4',
                );
            }
        }

        return new PhaseGeneree(
            phase: $phase,
            type: $this->type,
            groupes: ['principal' => array_map(static fn (Entite $e): string => $e->ref, $entites)],
            appariements: $appariements,
            tours: $tours,
            avertissements: $avertissements,
            meta: [
                'taille'   => $taille,
                'exempts'  => $taille - count($entites),
                'topologie' => 'elimination_simple',
            ],
        );
    }

    // -----------------------------------------------------------------
    // Topologie 2 — double elimination
    // -----------------------------------------------------------------

    /**
     * Branche des vainqueurs + branche des perdants + grande finale.
     *
     * La branche des perdants alterne deux types de tours :
     *
     *   - INJECTION : les survivants rencontrent les perdants qui
     *     viennent de redescendre de la branche des vainqueurs ;
     *   - CONSOLIDATION : les survivants s'affrontent entre eux pour
     *     revenir au meme effectif que la branche des vainqueurs.
     *
     * Cette alternance est ce qui donne le total classique de 2n-2
     * parties, et c'est aussi ce qui garantit qu'un joueur elimine a
     * bien perdu deux fois — le point que le §3.4 souligne comme la
     * propriete remarquable du format.
     *
     * @param  list<Entite> $entites
     * @param  list<string> $avertissements
     */
    private function doubleElimination(
        string $phase,
        array $entites,
        int $taille,
        Parametres $p,
        array $avertissements,
    ): PhaseGeneree {
        $principal = $this->eliminationSimple($phase, $entites, $taille, $p, []);
        $nbTours   = PlacementTableau::nombreTours($taille);

        $appariements = array_values(array_filter(
            $principal->appariements,
            static fn (Appariement $a): bool => $a->role === Appariement::ROLE_TABLEAU
        ));

        $tours = $principal->tours;

        /** @var array<int,list<string>> $parTour ids des matchs de la branche vainqueurs */
        $parTour = [];

        foreach ($appariements as $appariement) {
            $parTour[$appariement->tour][] = $appariement->id;
        }

        // --- branche des perdants -------------------------------------
        $tourLb     = 0;
        $survivants = [];

        // LB 1 : les perdants du premier tour s'apparient entre eux.
        $tourLb++;
        $ordre = 1;

        for ($i = 0; $i < count($parTour[1] ?? []); $i += 2) {
            if (! isset($parTour[1][$i + 1])) {
                break;
            }

            $id = sprintf('%s-P%d-%02d', $phase, $tourLb, $ordre);

            $appariements[] = new Appariement(
                id: $id,
                phase: $phase,
                a: Emplacement::perdantDe($parTour[1][$i]),
                b: Emplacement::perdantDe($parTour[1][$i + 1]),
                tour: $nbTours + $tourLb,
                ordre: $ordre,
                groupe: 'perdants',
                role: Appariement::ROLE_BRANCHE_PERDANTS,
                libelle: sprintf('Branche des perdants — tour %d', $tourLb),
            );

            $survivants[] = $id;
            $ordre++;
        }

        $tours[$nbTours + $tourLb] = sprintf('Perdants — tour %d', $tourLb);

        // Tours suivants : injection puis consolidation.
        for ($tour = 2; $tour <= $nbTours; $tour++) {
            $descendants = $parTour[$tour] ?? [];

            if ($descendants === [] || $survivants === []) {
                continue;
            }

            // Injection ------------------------------------------------
            $tourLb++;
            $ordre    = 1;
            $courant  = [];
            $nbMatchs = min(count($survivants), count($descendants));

            for ($i = 0; $i < $nbMatchs; $i++) {
                $id = sprintf('%s-P%d-%02d', $phase, $tourLb, $ordre);

                $appariements[] = new Appariement(
                    id: $id,
                    phase: $phase,
                    a: Emplacement::vainqueurDe($survivants[$i]),
                    // Croisement : le dernier descendant affronte le
                    // premier survivant, pour eviter une revanche
                    // immediate entre deux joueurs qui viennent de se
                    // rencontrer dans la branche des vainqueurs.
                    b: Emplacement::perdantDe($descendants[count($descendants) - 1 - $i]),
                    tour: $nbTours + $tourLb,
                    ordre: $ordre,
                    groupe: 'perdants',
                    role: Appariement::ROLE_BRANCHE_PERDANTS,
                    libelle: sprintf('Branche des perdants — tour %d', $tourLb),
                );

                $courant[] = $id;
                $ordre++;
            }

            $tours[$nbTours + $tourLb] = sprintf('Perdants — tour %d', $tourLb);
            $survivants = $courant;

            // Consolidation --------------------------------------------
            if (count($survivants) < 2) {
                continue;
            }

            $tourLb++;
            $ordre   = 1;
            $courant = [];

            for ($i = 0; $i < count($survivants); $i += 2) {
                if (! isset($survivants[$i + 1])) {
                    break;
                }

                $id = sprintf('%s-P%d-%02d', $phase, $tourLb, $ordre);

                $appariements[] = new Appariement(
                    id: $id,
                    phase: $phase,
                    a: Emplacement::vainqueurDe($survivants[$i]),
                    b: Emplacement::vainqueurDe($survivants[$i + 1]),
                    tour: $nbTours + $tourLb,
                    ordre: $ordre,
                    groupe: 'perdants',
                    role: Appariement::ROLE_BRANCHE_PERDANTS,
                    libelle: sprintf('Branche des perdants — tour %d', $tourLb),
                );

                $courant[] = $id;
                $ordre++;
            }

            $tours[$nbTours + $tourLb] = sprintf('Perdants — tour %d', $tourLb);
            $survivants = $courant;
        }

        // --- grande finale --------------------------------------------
        $finaleWb = $parTour[$nbTours][0] ?? null;
        $finaleLb = $survivants[0] ?? null;

        if ($finaleWb !== null && $finaleLb !== null) {
            $tourFinale = $nbTours + $tourLb + 1;

            $appariements[] = new Appariement(
                id: $phase . '-GF',
                phase: $phase,
                a: Emplacement::vainqueurDe($finaleWb, 'Vainqueur branche haute'),
                b: Emplacement::vainqueurDe($finaleLb, 'Vainqueur branche des perdants'),
                tour: $tourFinale,
                ordre: 1,
                groupe: 'finale',
                role: Appariement::ROLE_GRANDE_FINALE,
                libelle: 'Grande finale',
            );

            $tours[$tourFinale] = 'Grande finale';

            if ($p->booleen('grande_finale_reset')) {
                $appariements[] = new Appariement(
                    id: $phase . '-GF2',
                    phase: $phase,
                    a: Emplacement::vainqueurDe($finaleWb, 'Vainqueur branche haute'),
                    b: Emplacement::vainqueurDe($finaleLb, 'Vainqueur branche des perdants'),
                    tour: $tourFinale + 1,
                    ordre: 1,
                    groupe: 'finale',
                    role: Appariement::ROLE_RESET,
                    libelle: 'Belle (jouee seulement si la branche haute perd la grande finale)',
                    enjeu: 'Conditionnelle',
                );

                $tours[$tourFinale + 1] = 'Belle';
            }
        }

        return new PhaseGeneree(
            phase: $phase,
            type: $this->type,
            groupes: [
                'principal' => array_map(static fn (Entite $e): string => $e->ref, $entites),
            ],
            appariements: $appariements,
            tours: $tours,
            avertissements: $avertissements,
            meta: [
                'taille'    => $taille,
                'exempts'   => $taille - count($entites),
                'topologie' => 'double_elimination',
            ],
        );
    }

    // -----------------------------------------------------------------
    // Topologie 3 — classement integral
    // -----------------------------------------------------------------

    /**
     * Les vainqueurs continuent contre les vainqueurs, les perdants
     * contre les perdants, jusqu'a epuisement (§3.5).
     *
     * Tout le monde joue exactement log2(n) parties et le classement est
     * complet de 1 a n, sans ex aequo — la seule formule du document qui
     * offre les deux a la fois.
     *
     * @param  list<Entite> $entites
     * @param  list<string> $avertissements
     */
    private function classementIntegral(
        string $phase,
        array $entites,
        int $taille,
        Parametres $p,
        array $avertissements,
    ): PhaseGeneree {
        $nbTours   = PlacementTableau::nombreTours($taille);
        $positions = PlacementTableau::positions(count($entites), $taille);

        if ($taille !== count($entites)) {
            $avertissements[] = sprintf(
                'Classement integral avec %d exempt(s) : les places de bas de classement '
                . 'seront partiellement indeterminees.',
                $taille - count($entites)
            );
        }

        $appariements = [];
        $tours        = [];

        // Un « bloc » est un ensemble d'entites ayant le meme parcours
        // (memes victoires et memes defaites). Au tour 1 il y en a un ;
        // a chaque tour, chaque bloc se scinde en deux.
        $blocs = [[]];

        $ordre = 1;

        for ($i = 0; $i < $taille; $i += 2) {
            $a = $positions[$i] !== null
                ? Emplacement::entite($entites[$positions[$i]])
                : Emplacement::vide();
            $b = $positions[$i + 1] !== null
                ? Emplacement::entite($entites[$positions[$i + 1]])
                : Emplacement::vide();

            $id = sprintf('%s-T1-%02d', $phase, $ordre);

            $appariements[] = new Appariement(
                id: $id,
                phase: $phase,
                a: $a,
                b: $b,
                tour: 1,
                ordre: $ordre,
                groupe: 'places 1-' . $taille,
                role: Appariement::ROLE_TABLEAU,
                libelle: 'Tour 1 — partie ' . $ordre,
            );

            $blocs[0][] = $id;
            $ordre++;
        }

        $tours[1] = 'Tour 1';

        for ($tour = 2; $tour <= $nbTours; $tour++) {
            $suivants = [];
            $ordre    = 1;
            $haut     = 1;
            $largeur  = intdiv($taille, 2 ** ($tour - 1));

            foreach ($blocs as $bloc) {
                // Le bloc se scinde : vainqueurs d'un cote, perdants de
                // l'autre. Les deux sous-blocs jouent en parallele.
                foreach (['vainqueur', 'perdant'] as $issue) {
                    $ids = [];

                    for ($i = 0; $i < count($bloc); $i += 2) {
                        if (! isset($bloc[$i + 1])) {
                            break;
                        }

                        $id = sprintf('%s-T%d-%02d', $phase, $tour, $ordre);
                        $bas = $haut + $largeur - 1;

                        $appariements[] = new Appariement(
                            id: $id,
                            phase: $phase,
                            a: $issue === 'vainqueur'
                                ? Emplacement::vainqueurDe($bloc[$i])
                                : Emplacement::perdantDe($bloc[$i]),
                            b: $issue === 'vainqueur'
                                ? Emplacement::vainqueurDe($bloc[$i + 1])
                                : Emplacement::perdantDe($bloc[$i + 1]),
                            tour: $tour,
                            ordre: $ordre,
                            groupe: sprintf('places %d-%d', $haut, $bas),
                            role: Appariement::ROLE_CLASSEMENT,
                            libelle: sprintf('Tour %d — places %d a %d', $tour, $haut, $bas),
                            enjeu: sprintf('Places %d a %d', $haut, $bas),
                        );

                        $ids[] = $id;
                        $ordre++;
                    }

                    if ($ids !== []) {
                        $suivants[] = $ids;
                    }

                    $haut += $largeur;
                }
            }

            $blocs        = $suivants;
            $tours[$tour] = 'Tour ' . $tour;
        }

        return new PhaseGeneree(
            phase: $phase,
            type: $this->type,
            groupes: ['principal' => array_map(static fn (Entite $e): string => $e->ref, $entites)],
            appariements: $appariements,
            tours: $tours,
            avertissements: $avertissements,
            meta: [
                'taille'    => $taille,
                'topologie' => 'classement_integral',
                'parties_par_entite' => $nbTours,
            ],
        );
    }
}
