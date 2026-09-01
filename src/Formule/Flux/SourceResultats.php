<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Flux;

use RMCF\Tournois\Formule\Structure\Entite;

/**
 * Ce que le moteur de flux a besoin de savoir d'une phase terminee.
 *
 * L'interface est deliberement etroite : sept questions, aucune ecriture.
 * C'est ce qui permet de tester tout le moteur de flux sans base de
 * donnees, avec l'implementation en memoire, puis de brancher un
 * repository PDO sans toucher une ligne du moteur.
 *
 * Elle rend des REFERENCES d'entites, jamais des rangs ni des scores :
 * le classement est calcule ailleurs (MoteurClassement), le flux ne fait
 * que consommer son resultat.
 */
interface SourceResultats
{
    /**
     * Les groupes d'une phase, dans l'ordre d'affichage.
     *
     * @return list<string>
     */
    public function groupes(string $phase): array;

    /**
     * Le classement d'un groupe, du 1er au dernier.
     *
     * @return list<string> references d'entites
     */
    public function classementGroupe(string $phase, string $groupe): array;

    /**
     * Le classement inter-groupes de toute la phase.
     *
     * @return list<string>
     */
    public function classementGlobal(string $phase): array;

    /**
     * Les perdants du tour `$tour` d'un tableau.
     *
     * @return list<string>
     */
    public function perdantsTour(string $phase, int $tour): array;

    /** @return list<string> */
    public function vainqueursTour(string $phase, int $tour): array;

    /** Nombre de defaites d'une entite dans une phase (§3.4). */
    public function defaites(string $phase, string $entite): int;

    /** Toutes les entites ayant pris part a la phase. */
    public function entite(string $ref): ?Entite;

    /** La phase est-elle close (tous les resultats saisis) ? */
    public function estClose(string $phase): bool;
}
