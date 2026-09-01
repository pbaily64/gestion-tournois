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
 * Formule 9 — montante-descendante / king of the table (§5.9).
 *
 * Les joueurs sont repartis sur n tables ; a chaque tour, le vainqueur
 * monte d'une table et le perdant descend. Le classement final est
 * simplement la position occupee a la derniere table.
 *
 * La formule s'auto-organise : il n'y a pas de tableau a tenir, et c'est
 * precisement son interet pour une soiree d'entrainement. Le generateur
 * ne produit donc que la REPARTITION INITIALE et le premier tour ; les
 * tours suivants se deduisent mecaniquement des resultats, par
 * `tourSuivant()`.
 *
 * Deux joueurs par table est le cas standard. Avec quatre par table, on
 * joue une mini-poule et les deux premiers montent — variante geree par
 * `regle_montee = vainqueur_monte_2`.
 */
final class GenerateurEchelle implements Generateur
{
    public function type(): string
    {
        return 'echelle';
    }

    public function generer(string $phase, Plateau $entrants, Parametres $p): PhaseGeneree
    {
        $entites  = $entrants->parRang()->entites();
        $effectif = count($entites);

        if ($effectif < 2) {
            return new PhaseGeneree($phase, 'echelle', [], [], [], ['Moins de deux entrants.']);
        }

        $parTable = $p->texte('regle_montee', 'vainqueur_monte') === 'vainqueur_monte_2' ? 4 : 2;
        $nbTables = $p->estRenseigne('nb_tables_echelle')
            ? max(1, $p->entier('nb_tables_echelle') ?? 1)
            : max(1, intdiv($effectif, $parTable));

        $tailles = GenerateurPoules::equilibrer($effectif, $nbTables);
        $groupes = [];
        $tables  = [];
        $offset  = 0;

        foreach ($tailles as $i => $taille) {
            $membres = array_slice($entites, $offset, $taille);
            $offset += $taille;

            $libelle           = 'Table ' . ($i + 1);
            $groupes[$libelle] = array_map(static fn (Entite $e): string => $e->ref, $membres);
            $tables[$libelle]  = $membres;
        }

        $appariements   = $this->tour($phase, 1, $tables);
        $avertissements = [
            'Montante-descendante : seul le premier tour est genere, les suivants '
            . 'decoulent des resultats.',
        ];

        if ($effectif % $parTable !== 0) {
            $avertissements[] = sprintf(
                'Effectif non multiple de %d : les tables n\'ont pas toutes le meme nombre de joueurs.',
                $parTable
            );
        }

        return new PhaseGeneree(
            phase: $phase,
            type: 'echelle',
            groupes: $groupes,
            appariements: $appariements,
            tours: [1 => 'Tour 1'],
            avertissements: $avertissements,
            meta: [
                'nb_tables'        => $nbTables,
                'par_table'        => $parTable,
                'classement_final' => $p->texte('classement_final_echelle', 'position_table'),
            ],
        );
    }

    public function volume(int $effectif, Parametres $p): int
    {
        $parTable = $p->texte('regle_montee', 'vainqueur_monte') === 'vainqueur_monte_2' ? 4 : 2;
        $nbTables = max(1, intdiv($effectif, $parTable));
        $tours    = $p->estRenseigne('nb_tours') ? max(1, $p->entier('nb_tours') ?? 1) : $nbTables;

        return $tours * $nbTables * ($parTable === 4 ? 6 : 1);
    }

    /**
     * Appariements d'un tour, tables deja composees.
     *
     * @param  array<string,list<Entite>> $tables
     * @return list<Appariement>
     */
    public function tour(string $phase, int $numero, array $tables): array
    {
        $appariements = [];
        $ordre        = 1;

        foreach ($tables as $libelle => $membres) {
            $sequence = OrdreParties::pour(count($membres));

            foreach ($sequence as $partie) {
                $a = $membres[OrdreParties::indiceLettre($partie[0])] ?? null;
                $b = $membres[OrdreParties::indiceLettre($partie[1])] ?? null;

                if ($a === null || $b === null) {
                    continue;
                }

                $appariements[] = new Appariement(
                    id: sprintf('%s-E%d-%02d', $phase, $numero, $ordre),
                    phase: $phase,
                    a: Emplacement::entite($a),
                    b: Emplacement::entite($b),
                    tour: $numero,
                    ordre: $ordre,
                    groupe: $libelle,
                    role: Appariement::ROLE_ECHELLE,
                    libelle: sprintf('%s — tour %d', $libelle, $numero),
                    enjeu: 'Le vainqueur monte, le perdant descend',
                );

                $ordre++;
            }
        }

        return $appariements;
    }

    /**
     * Recompose les tables apres un tour.
     *
     * @param  array<string,list<Entite>> $tables    tables du tour ecoule
     * @param  array<string,string>       $vainqueurs libelle table => ref
     * @return array<string,list<Entite>>
     */
    public function recomposer(array $tables, array $vainqueurs): array
    {
        $libelles = array_keys($tables);
        $suivant  = array_fill_keys($libelles, []);

        foreach ($libelles as $i => $libelle) {
            $haut = $libelles[$i - 1] ?? $libelle;   // la table 1 est le sommet
            $bas  = $libelles[$i + 1] ?? $libelle;   // la derniere est le fond

            foreach ($tables[$libelle] as $entite) {
                $gagnant = ($vainqueurs[$libelle] ?? null) === $entite->ref;
                $suivant[$gagnant ? $haut : $bas][] = $entite;
            }
        }

        return $suivant;
    }
}
