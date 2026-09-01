<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Generation;

use RMCF\Tournois\Formule\Parametres;
use RMCF\Tournois\Formule\Structure\PhaseGeneree;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * Contrat commun aux sept briques d'appariement du §3.
 *
 * Un generateur transforme un plateau d'entrants en structure de phase.
 * Il ne lit que des parametres, ne touche ni a la base ni au HTML, et
 * ne produit aucun resultat : il dessine, il n'arbitre pas.
 *
 * C'est ce contrat unique qui rend la matrice de couverture C.12 vraie.
 * Ajouter une formule au module, c'est ajouter une implementation ici —
 * ou, dans la plupart des cas, ne rien ajouter du tout et se contenter
 * de changer des parametres (RG-22).
 */
interface Generateur
{
    /**
     * Type de phase pris en charge (`type_phase` du catalogue).
     */
    public function type(): string;

    /**
     * Dessine la phase.
     *
     * @param string   $phase    code de la phase, prefixe des ids
     * @param Plateau  $entrants entites, DANS L'ORDRE DES TETES DE SERIE
     */
    public function generer(string $phase, Plateau $entrants, Parametres $p): PhaseGeneree;

    /**
     * Nombre de parties que produira la generation, sans la faire.
     *
     * Sert a l'estimation de volume (RG-91) : on doit pouvoir dire
     * « cette configuration demande 94 parties, soit 4 h 20 sur 6
     * tables » avant d'ouvrir les inscriptions.
     */
    public function volume(int $effectif, Parametres $p): int;
}
