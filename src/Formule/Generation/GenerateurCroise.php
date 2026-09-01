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
 * Appariement croise entre deux groupes disjoints — le Scheveningen (§2.6).
 *
 * Deux groupes A et B ; chaque membre de A rencontre chaque membre de B,
 * et personne ne rencontre quelqu'un de son propre groupe. C'est la
 * formule des rencontres inter-clubs et inter-generations.
 *
 * C'EST LA PREMIERE DES DEUX LACUNES ASSUMEES de la matrice de couverture
 * C.12. Le document conclut que ce n'est ni une poule ni une elimination
 * et qu'il faudrait une valeur `type_phase = croise` : la voici. Le cout
 * annonce comme faible l'etait effectivement — le seul travail reel est
 * l'ordonnancement.
 *
 * ORDONNANCEMENT — un produit cartesien naif fait jouer A1 contre B1, B2,
 * B3… d'affilee, ce qui est injouable : A1 enchaine tout et les autres
 * attendent. On utilise donc la rotation a la Berger : au tour k, A(i)
 * rencontre B((i+k) mod n). Chaque tour fait jouer TOUT LE MONDE
 * exactement une fois, ce qui est exactement ce que demande une soiree
 * ou les tables doivent tourner en continu.
 *
 * Quand les deux groupes n'ont pas la meme taille, les tours sont
 * partiels : les surnumeraires du plus grand groupe sont au repos, a
 * tour de role.
 */
final class GenerateurCroise implements Generateur
{
    public function type(): string
    {
        return 'croise';
    }

    public function generer(string $phase, Plateau $entrants, Parametres $p): PhaseGeneree
    {
        [$a, $b, $avertissements] = $this->scinder($entrants, $p);

        if ($a === [] || $b === []) {
            return new PhaseGeneree(
                $phase,
                'croise',
                [],
                [],
                [],
                ['Un appariement croise exige deux groupes non vides.']
            );
        }

        $nbA     = count($a);
        $nbB     = count($b);
        $largeur = max($nbA, $nbB);

        $appariements = [];
        $tours        = [];

        for ($tour = 0; $tour < $largeur; $tour++) {
            $ordre  = 1;
            $reelle = 0;

            for ($i = 0; $i < $largeur; $i++) {
                $x = $a[$i % $nbA] ?? null;
                $y = $b[($i + $tour) % $nbB] ?? null;

                // Avec des tailles inegales, la rotation reproduit des
                // paires deja vues : on ne les emet qu'une fois.
                if ($x === null || $y === null || $i >= $nbA || $tour >= $nbB) {
                    continue;
                }

                $appariements[] = new Appariement(
                    id: sprintf('%s-C%d-%02d', $phase, $tour + 1, $ordre),
                    phase: $phase,
                    a: Emplacement::entite($x),
                    b: Emplacement::entite($y),
                    tour: $tour + 1,
                    ordre: $ordre,
                    groupe: 'croise',
                    role: Appariement::ROLE_CROISE,
                    libelle: sprintf('Tour %d — %s contre %s', $tour + 1, $x->libelle, $y->libelle),
                );

                $ordre++;
                $reelle++;
            }

            if ($reelle > 0) {
                $tours[$tour + 1] = 'Tour ' . ($tour + 1);
            }
        }

        if ($nbA !== $nbB) {
            $avertissements[] = sprintf(
                'Groupes de tailles inegales (%d contre %d) : les entites du groupe le plus '
                . 'nombreux ne disputent pas le meme nombre de parties. Le classement doit '
                . 'donc utiliser des ratios, jamais des totaux.',
                $nbA,
                $nbB
            );
        }

        return new PhaseGeneree(
            phase: $phase,
            type: 'croise',
            groupes: [
                'A' => array_map(static fn (Entite $e): string => $e->ref, $a),
                'B' => array_map(static fn (Entite $e): string => $e->ref, $b),
            ],
            appariements: $appariements,
            tours: $tours,
            avertissements: $avertissements,
            meta: ['taille_a' => $nbA, 'taille_b' => $nbB],
        );
    }

    public function volume(int $effectif, Parametres $p): int
    {
        $a = intdiv($effectif, 2);

        return $a * ($effectif - $a);
    }

    /**
     * Scinde le plateau en deux groupes.
     *
     * Si les entrants portent deja une origine (« A » / « B »), on la
     * respecte : c'est le cas d'un croise entre deux poules ou entre
     * deux clubs. Sinon on coupe le plateau en deux moities selon le
     * classement, ce qui donne le croise « forts contre faibles ».
     *
     * @return array{0:list<Entite>,1:list<Entite>,2:list<string>}
     */
    private function scinder(Plateau $entrants, Parametres $p): array
    {
        $origines = $entrants->parOrigine();
        unset($origines['']);

        if (count($origines) === 2) {
            $cles = array_keys($origines);

            return [array_values($origines[$cles[0]]), array_values($origines[$cles[1]]), []];
        }

        if (count($origines) > 2) {
            return [[], [], [sprintf(
                'Appariement croise : %d groupes d\'origine detectes, il en faut exactement 2.',
                count($origines)
            )]];
        }

        $entites = $entrants->parRang()->entites();
        $milieu  = intdiv(count($entites), 2);

        return [
            array_slice($entites, 0, $milieu),
            array_slice($entites, $milieu),
            ['Aucun groupe d\'origine : le plateau a ete coupe en deux moities par classement.'],
        ];
    }
}
