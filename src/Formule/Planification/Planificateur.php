<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Planification;

use RMCF\Tournois\Formule\FormatPartie;
use RMCF\Tournois\Formule\Parametres;
use RMCF\Tournois\Formule\Structure\Appariement;

/**
 * L'affectation des parties aux tables (C.11).
 *
 * Le planificateur repond a la seule question que se pose la table de
 * marque : « quelle partie je lance maintenant, et sur quelle table ? »
 *
 * Deux contraintes le gouvernent, et la premiere est absolue :
 *
 *   RG-90  un joueur ne peut pas etre engage sur deux tables a la fois.
 *          Lancer une partie dont un camp joue deja est l'erreur qui
 *          desorganise une soiree entiere, parce qu'elle ne se voit
 *          qu'au moment ou l'on appelle les joueurs.
 *
 *   RG-91  le repos minimum entre deux parties. Contrainte souple :
 *          on la respecte si l'on peut, on la signale sinon. La faire
 *          bloquante immobiliserait des tables libres en fin de soiree,
 *          ce qui est pire que le mal.
 *
 * Le planificateur ne decide pas de l'ordre des parties : cet ordre est
 * deja porte par les generateurs (sequences officielles de poule,
 * tours de tableau). Il se contente de le respecter en le filtrant.
 */
final class Planificateur
{
    /** @param array<string,int> $dernierePartieTerminee ref joueur => minute */
    public function __construct(
        private readonly int $nbTables,
        private readonly int $reposMinimum = 5,
        private readonly array $dernierePartieTerminee = [],
    ) {
    }

    /**
     * Choisit les parties a lancer maintenant.
     *
     * @param  list<Appariement>  $candidats   dans l'ordre souhaite
     * @param  list<Appariement>  $enCours     parties deja lancees
     * @param  int                $minute      instant courant, en minutes
     * @return list<Affectation>
     */
    public function lancer(array $candidats, array $enCours, int $minute = 0): array
    {
        $occupes = $this->joueursEngages($enCours);
        $libres  = $this->tablesLibres($enCours);

        $affectations = [];

        foreach ($candidats as $appariement) {
            if ($libres === []) {
                break;
            }

            if (! $appariement->estLancable() || $appariement->estExempt()) {
                continue;
            }

            $camps = $this->campsDe($appariement);

            // RG-90 — refus absolu.
            $conflit = false;

            foreach ($camps as $camp) {
                if (isset($occupes[$camp])) {
                    $conflit = true;
                    break;
                }
            }

            if ($conflit) {
                continue;
            }

            $alerte = null;

            foreach ($camps as $camp) {
                $fin = $this->dernierePartieTerminee[$camp] ?? null;

                if ($fin !== null && $minute - $fin < $this->reposMinimum) {
                    $alerte = sprintf(
                        '%s enchaîne après %d minute(s) de repos (minimum %d).',
                        $camp,
                        max(0, $minute - $fin),
                        $this->reposMinimum
                    );
                }
            }

            $table = array_shift($libres);

            $affectations[] = new Affectation($appariement, $table, $minute, $alerte);

            foreach ($camps as $camp) {
                $occupes[$camp] = true;
            }
        }

        return $affectations;
    }

    /**
     * Estimation de volume et de duree (RG-91).
     *
     * @param  list<Appariement> $appariements
     * @return array{parties:int,minutes:int,tables:int,par_table:int}
     */
    public function estimer(array $appariements, Parametres $parametres): array
    {
        $format = FormatPartie::depuisParametres($parametres);
        $duree  = $parametres->entier('duree_estimee_partie');

        if ($duree === null || $duree <= 0) {
            $duree = $format->dureeEstimee();
        }

        $parties = count(array_filter(
            $appariements,
            static fn (Appariement $a): bool => ! $a->estExempt()
        ));

        $tables    = max(1, $this->nbTables);
        $parTable  = (int) ceil($parties / $tables);

        return [
            'parties'   => $parties,
            'minutes'   => $parTable * $duree,
            'tables'    => $tables,
            'par_table' => $parTable,
        ];
    }

    /**
     * @param  list<Appariement> $enCours
     * @return array<string,bool>
     */
    private function joueursEngages(array $enCours): array
    {
        $occupes = [];

        foreach ($enCours as $appariement) {
            foreach ($this->campsDe($appariement) as $camp) {
                $occupes[$camp] = true;
            }
        }

        return $occupes;
    }

    /**
     * @param  list<Appariement> $enCours
     * @return list<int>
     */
    private function tablesLibres(array $enCours): array
    {
        $toutes = range(1, max(1, $this->nbTables));

        return array_values(array_slice($toutes, count($enCours)));
    }

    /**
     * @param  Appariement $appariement
     * @return list<string>
     */
    private function campsDe(Appariement $appariement): array
    {
        $camps = [];

        foreach ([$appariement->a, $appariement->b] as $cote) {
            if ($cote->estConnu() && $cote->reference !== null) {
                $camps[] = $cote->reference;
            }
        }

        return $camps;
    }
}
