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
 * Brique 7 — le barrage (§3.7).
 *
 * Ce n'est pas une formule de tournoi mais un MECANISME DE RESOLUTION :
 * on l'insere la ou une decision manque, generalement entre la fin des
 * poules et l'entree du tableau, pour departager des joueurs de poules
 * differentes qui se disputent les dernieres places qualificatives.
 *
 * C'est la position validee sur le MbN, et c'est aussi ce que fait
 * l'ITTF aux championnats du monde par equipes : les meilleurs deuxiemes
 * passent d'office sur les ratios, les autres disputent un barrage.
 *
 * Le nombre de places a pourvoir (`nb_qualifies`) est ce qui determine
 * la forme :
 *
 *   - 2 barragistes pour 1 place        -> match unique
 *   - 3 barragistes ou plus             -> mini-poule ou elimination
 *     directe, selon `format_barrage`
 *
 * L'appariement d'une elimination de barrage suit la meme geometrie que
 * celle d'un tableau : le mieux classe rencontre le moins bien classe.
 */
final class GenerateurBarrage implements Generateur
{
    public function __construct(private readonly int $graine = 0)
    {
    }

    public function type(): string
    {
        return 'barrage';
    }

    public function generer(string $phase, Plateau $entrants, Parametres $p): PhaseGeneree
    {
        $entites  = $entrants->parRang()->entites();
        $effectif = count($entites);

        if ($effectif < 2) {
            return new PhaseGeneree(
                $phase,
                'barrage',
                [],
                [],
                [],
                ['Barrage sans objet : moins de deux barragistes.']
            );
        }

        $format = $p->texte('format_barrage', 'match_unique');

        if ($format === 'match_unique' && $effectif > 2) {
            $format = 'elimination_directe';
        }

        $avertissements = [];
        $places         = $p->entier('nb_qualifies', 1) ?? 1;

        if ($places >= $effectif) {
            $avertissements[] = sprintf(
                'Barrage inutile : %d place(s) pour %d barragiste(s).',
                $places,
                $effectif
            );
        }

        $appariements = match ($format) {
            'mini_poule' => $this->miniPoule($phase, $entites, $p),
            default      => $this->elimination($phase, $entites, $p),
        };

        if ($p->booleen('lieu_neutre')) {
            $avertissements[] = 'Barrage a disputer sur table neutre (FRBTT art. A.2.4.2).';
        }

        $persistance = $p->texte('si_egalite_persistante', 'classement_officiel');

        return new PhaseGeneree(
            phase: $phase,
            type: 'barrage',
            groupes: ['barrage' => array_map(static fn (Entite $e): string => $e->ref, $entites)],
            appariements: $appariements,
            tours: $this->tours($appariements),
            avertissements: $avertissements,
            meta: [
                'format'                 => $format,
                'places_a_pourvoir'      => $places,
                'objet'                  => $p->texte('objet', 'acces_tableau'),
                'si_egalite_persistante' => $persistance,
            ],
        );
    }

    public function volume(int $effectif, Parametres $p): int
    {
        if ($effectif < 2) {
            return 0;
        }

        if ($p->texte('format_barrage', 'match_unique') === 'mini_poule') {
            return OrdreParties::nombreParties($effectif);
        }

        // Chaque partie elimine exactement une entite : pour ramener
        // $effectif barragistes a $places qualifies, il en faut
        // exactement la difference.
        $places = max(1, $p->entier('nb_qualifies', 1) ?? 1);

        return max(0, $effectif - $places);
    }

    /**
     * Elimination directe TRONQUEE au nombre de places a pourvoir.
     *
     * C'est le point ou un barrage differe d'un tableau : un tableau se
     * joue jusqu'a n'avoir qu'un vainqueur, un barrage s'arrete des
     * qu'il reste autant de survivants que de places. Huit barragistes
     * pour quatre places, c'est UN tour de quatre parties — pas sept.
     *
     * Faire jouer les trois parties de trop n'est pas seulement une
     * perte de temps un soir de tournoi : cela designe un « vainqueur du
     * barrage » qui n'a aucune existence reglementaire et fausse le
     * placement des quatre qualifies dans le tableau.
     *
     * @param  list<Entite> $entites
     * @return list<Appariement>
     */
    private function elimination(string $phase, array $entites, Parametres $p): array
    {
        $tableau = new GenerateurTableau('barrage', $this->graine);
        $genere  = $tableau->generer($phase, new Plateau($entites), $p->avec([
            'defaites_tolerees' => 1,
            'taille_tableau'    => 'auto',
            'petite_finale'     => false,
        ]));

        $places  = max(1, $p->entier('nb_qualifies', 1) ?? 1);
        $taille  = (int) ($genere->meta['taille'] ?? count($entites));
        $survivants = $taille;
        $dernierTour = 0;

        while ($survivants > $places) {
            $dernierTour++;
            $survivants = intdiv($survivants, 2);
        }

        $retenus = array_values(array_filter(
            $genere->appariements,
            static fn (Appariement $a): bool => $a->tour <= max(1, $dernierTour)
        ));

        return array_map(
            static fn (Appariement $a): Appariement => new Appariement(
                id: $a->id,
                phase: $a->phase,
                a: $a->a,
                b: $a->b,
                tour: $a->tour,
                ordre: $a->ordre,
                groupe: 'barrage',
                role: Appariement::ROLE_BARRAGE,
                libelle: 'Barrage — ' . ($a->libelle ?? ''),
                enjeu: $p->texte('objet', 'acces_tableau'),
            ),
            $retenus
        );
    }

    /**
     * @param  list<Entite> $entites
     * @return list<Appariement>
     */
    private function miniPoule(string $phase, array $entites, Parametres $p): array
    {
        $sequence     = OrdreParties::pour(count($entites));
        $appariements = [];
        $ordre        = 1;

        foreach ($sequence as $partie) {
            $a = $entites[OrdreParties::indiceLettre($partie[0])] ?? null;
            $b = $entites[OrdreParties::indiceLettre($partie[1])] ?? null;

            if ($a === null || $b === null) {
                continue;
            }

            $appariements[] = new Appariement(
                id: sprintf('%s-B-%02d', $phase, $ordre),
                phase: $phase,
                a: Emplacement::entite($a),
                b: Emplacement::entite($b),
                tour: 1,
                ordre: $ordre,
                groupe: 'barrage',
                role: Appariement::ROLE_BARRAGE,
                libelle: sprintf('Barrage — partie %d', $ordre),
                enjeu: $p->texte('objet', 'acces_tableau'),
            );

            $ordre++;
        }

        return $appariements;
    }

    /**
     * @param  list<Appariement> $appariements
     * @return array<int,string>
     */
    private function tours(array $appariements): array
    {
        $tours = [];

        foreach ($appariements as $appariement) {
            $tours[$appariement->tour] = 'Barrage — tour ' . $appariement->tour;
        }

        ksort($tours);

        return $tours;
    }
}
