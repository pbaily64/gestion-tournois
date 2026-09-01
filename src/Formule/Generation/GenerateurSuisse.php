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
 * Brique 6 — le systeme suisse (§3.6).
 *
 * Une particularite le distingue de toutes les autres briques : il est
 * le SEUL format qui ne peut pas etre entierement genere a l'avance.
 * L'appariement du tour k+1 depend des resultats du tour k, et aucune
 * provenance ne peut l'exprimer — « le joueur qui aura 2 victoires et
 * le meilleur Buchholz » n'est pas une reference a un match.
 *
 * Ce generateur produit donc le TOUR 1 seul, et expose `tourSuivant()`
 * pour la suite. C'est un cout operationnel reel, deja identifie au
 * §12.4 : pour une soiree ou les tables doivent tourner en continu, il
 * faut attendre la fin d'un tour complet avant de lancer le suivant.
 *
 * APPARIEMENT — trois variantes reconnues :
 *
 *   neerlandais  la moitie haute rencontre la moitie basse, dans
 *                l'ordre : dans un groupe de 8, 1-5, 2-6, 3-7, 4-8 ;
 *   monrad       les voisins immediats : 1-2, 3-4, 5-6, 7-8 ;
 *   aleatoire    tirage graine, reproductible.
 *
 * L'interdiction de revanche est absolue (§3.6) : si l'appariement
 * naturel reproduit une confrontation deja jouee, on decale le partenaire
 * d'un rang, autant de fois que necessaire.
 */
final class GenerateurSuisse implements Generateur
{
    public function __construct(private readonly int $graine = 0)
    {
    }

    public function type(): string
    {
        return 'suisse';
    }

    public function generer(string $phase, Plateau $entrants, Parametres $p): PhaseGeneree
    {
        $effectif = $entrants->effectif();
        $nbTours  = $this->nbTours($effectif, $p);

        if ($effectif < 2) {
            return new PhaseGeneree($phase, 'suisse', [], [], [], ['Moins de deux entrants.']);
        }

        $ordre = $p->texte('appariement_tour_1', 'tetes_de_serie') === 'aleatoire'
            ? $entrants->melanger($this->graine)
            : $entrants->parRang();

        $resultat = $this->apparier($phase, 1, $ordre->entites(), $p, [], []);

        $tours = [];

        for ($t = 1; $t <= $nbTours; $t++) {
            $tours[$t] = 'Tour ' . $t;
        }

        $avertissements = [
            sprintf(
                'Systeme suisse : seul le tour 1 est genere. Les %d tour(s) suivant(s) '
                . 'seront apparies a la cloture du tour precedent.',
                max(0, $nbTours - 1)
            ),
        ];

        if ($effectif % 2 === 1) {
            $avertissements[] = 'Effectif impair : un exempt par tour, credite d\'une victoire.';
        }

        return new PhaseGeneree(
            phase: $phase,
            type: 'suisse',
            groupes: ['suisse' => $entrants->refs()],
            appariements: $resultat,
            tours: $tours,
            avertissements: $avertissements,
            meta: ['nb_tours' => $nbTours, 'tours_generes' => 1],
        );
    }

    public function volume(int $effectif, Parametres $p): int
    {
        return $this->nbTours($effectif, $p) * intdiv($effectif, 2);
    }

    /**
     * Apparie le tour `$tour` a partir du classement provisoire.
     *
     * @param  list<Entite>                   $classement    ordonne, meilleur d'abord
     * @param  list<array{0:string,1:string}> $dejaJoues
     * @param  list<string>                   $dejaExempts
     * @return list<Appariement>
     */
    public function tourSuivant(
        string $phase,
        int $tour,
        array $classement,
        Parametres $p,
        array $dejaJoues,
        array $dejaExempts,
    ): array {
        return $this->apparier($phase, $tour, $classement, $p, $dejaJoues, $dejaExempts);
    }

    /**
     * @param  list<Entite>                   $classement
     * @param  list<array{0:string,1:string}> $dejaJoues
     * @param  list<string>                   $dejaExempts
     * @return list<Appariement>
     */
    private function apparier(
        string $phase,
        int $tour,
        array $classement,
        Parametres $p,
        array $dejaJoues,
        array $dejaExempts,
    ): array {
        $appariements = [];
        $exempt       = null;

        if (count($classement) % 2 === 1) {
            [$classement, $exempt] = $this->retirerExempt($classement, $p, $dejaExempts);
        }

        $restants = $classement;
        $ordre    = 1;
        $mode     = $p->texte('appariement', 'neerlandais');
        $interdireRevanche = $p->estRenseigne('interdire_revanche')
            ? $p->booleen('interdire_revanche')
            : true;

        while (count($restants) >= 2) {
            $premier = array_shift($restants);
            $indice  = $this->indiceAdversaire($mode, count($restants));

            if ($interdireRevanche) {
                $indice = $this->decalerSiRevanche($premier, $restants, $indice, $dejaJoues);
            }

            $adversaire = $restants[$indice] ?? $restants[0];
            unset($restants[$indice]);
            $restants = array_values($restants);

            $appariements[] = new Appariement(
                id: sprintf('%s-S%d-%02d', $phase, $tour, $ordre),
                phase: $phase,
                a: Emplacement::entite($premier),
                b: Emplacement::entite($adversaire),
                tour: $tour,
                ordre: $ordre,
                groupe: 'suisse',
                role: Appariement::ROLE_SUISSE,
                libelle: sprintf('Tour %d — partie %d', $tour, $ordre),
            );

            $dejaJoues[] = [$premier->ref, $adversaire->ref];
            $ordre++;
        }

        if ($exempt !== null) {
            $appariements[] = new Appariement(
                id: sprintf('%s-S%d-EX', $phase, $tour),
                phase: $phase,
                a: Emplacement::entite($exempt),
                b: Emplacement::vide(),
                tour: $tour,
                ordre: $ordre,
                groupe: 'suisse',
                role: Appariement::ROLE_SUISSE,
                libelle: sprintf('Tour %d — exempt', $tour),
                enjeu: 'Exempt : credite comme une victoire',
            );
        }

        return $appariements;
    }

    /**
     * Indice, dans la liste des restants, de l'adversaire naturel.
     *
     * En neerlandais, le premier de la moitie haute rencontre le premier
     * de la moitie basse : une fois le meneur retire, cet adversaire est
     * a l'indice « milieu de ce qui reste ». En monrad, c'est le voisin
     * immediat, donc l'indice 0.
     */
    private function indiceAdversaire(string $mode, int $restants): int
    {
        if ($restants === 0) {
            return 0;
        }

        return match ($mode) {
            'monrad', 'burstein' => 0,
            'aleatoire'          => 0,
            default              => min($restants - 1, intdiv($restants, 2)),
        };
    }

    /**
     * @param  list<Entite>                   $restants
     * @param  list<array{0:string,1:string}> $dejaJoues
     */
    private function decalerSiRevanche(Entite $premier, array $restants, int $indice, array $dejaJoues): int
    {
        $nb = count($restants);

        for ($decalage = 0; $decalage < $nb; $decalage++) {
            foreach ([$indice + $decalage, $indice - $decalage] as $candidat) {
                if ($candidat < 0 || $candidat >= $nb) {
                    continue;
                }

                if (! $this->dejaRencontres($premier->ref, $restants[$candidat]->ref, $dejaJoues)) {
                    return $candidat;
                }
            }
        }

        return $indice;
    }

    /** @param list<array{0:string,1:string}> $dejaJoues */
    private function dejaRencontres(string $a, string $b, array $dejaJoues): bool
    {
        foreach ($dejaJoues as $paire) {
            if (($paire[0] === $a && $paire[1] === $b) || ($paire[0] === $b && $paire[1] === $a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Entite> $classement
     * @param  list<string> $dejaExempts
     * @return array{0:list<Entite>,1:Entite|null}
     */
    private function retirerExempt(array $classement, Parametres $p, array $dejaExempts): array
    {
        $politique = $p->texte('politique_exempt', 'jamais_deux_fois');

        // Par defaut on exempte le plus faible n'ayant pas encore ete
        // exempt : c'est la regle la plus repandue, et la seule qui ne
        // penalise jamais deux fois la meme personne.
        $candidats = array_reverse(array_keys($classement));

        foreach ($candidats as $i) {
            if ($politique === 'jamais_deux_fois' && in_array($classement[$i]->ref, $dejaExempts, true)) {
                continue;
            }

            $exempt = $classement[$i];
            unset($classement[$i]);

            return [array_values($classement), $exempt];
        }

        $exempt = array_pop($classement);

        return [array_values($classement), $exempt];
    }

    private function nbTours(int $effectif, Parametres $p): int
    {
        if (! $p->estAuto('nb_tours') && $p->estRenseigne('nb_tours')) {
            return max(1, $p->entier('nb_tours') ?? 1);
        }

        // ceil(log2 n) suffit a degager un vainqueur unique ; en pratique
        // on ajoute un tour pour fiabiliser le classement (§3.6).
        return $effectif <= 1 ? 1 : (int) ceil(log($effectif, 2)) + 1;
    }
}
