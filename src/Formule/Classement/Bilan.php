<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

/**
 * Bilan chiffre d'une entite sur un ensemble de parties.
 *
 * Toutes les valeurs de criteres de la section 7.4 se lisent ici. Le
 * bilan est toujours calcule sur une PORTEE — toute la poule, ou les
 * seules parties entre ex aequo — et n'a donc de sens qu'accompagne de
 * celle-ci.
 *
 * CONVENTION POUR LES QUOTIENTS
 *
 * Diviser par zero n'est pas une erreur ici : un joueur invaincu a zero
 * defaite, et son quotient est infini au sens du reglement. La valeur
 * INFINI le represente, assez grande pour dominer tout quotient reel et
 * assez petite pour rester un nombre comparable et affichable.
 */
final class Bilan
{
    /** Valeur representant un quotient a denominateur nul. */
    public const INFINI = 999999.0;

    public int $parties        = 0;
    public int $victoires      = 0;
    public int $defaites       = 0;
    public int $nuls           = 0;
    public int $forfaitsSubis  = 0;
    public int $pointsRencontre = 0;
    public int $manchesPour    = 0;
    public int $manchesContre  = 0;
    public int $pointsPour     = 0;
    public int $pointsContre   = 0;

    /** @var list<string> adversaires rencontres, pour le Buchholz */
    public array $adversaires = [];

    public function diffManches(): int
    {
        return $this->manchesPour - $this->manchesContre;
    }

    public function manchesJouees(): int
    {
        return $this->manchesPour + $this->manchesContre;
    }

    public function diffPoints(): int
    {
        return $this->pointsPour - $this->pointsContre;
    }

    /** Manches gagnees / manches perdues (section 7.4, critere 6). */
    public function quotientManches(): float
    {
        return self::quotient($this->manchesPour, $this->manchesContre);
    }

    /**
     * Manches gagnees / manches jouees (critere 11).
     *
     * C'est le critere comparable entre poules de tailles differentes,
     * et la recommandation consolidee de la section 7.6 en manches
     * gagnantes.
     */
    public function ratioManches(): float
    {
        $jouees = $this->manchesJouees();

        return $jouees === 0 ? 0.0 : $this->manchesPour / $jouees;
    }

    /** Difference de manches ramenee au nombre de parties (approche B). */
    public function diffManchesNormalisee(): float
    {
        return $this->parties === 0 ? 0.0 : $this->diffManches() / $this->parties;
    }

    public function quotientPoints(): float
    {
        return self::quotient($this->pointsPour, $this->pointsContre);
    }

    public function ratioPoints(): float
    {
        $total = $this->pointsPour + $this->pointsContre;

        return $total === 0 ? 0.0 : $this->pointsPour / $total;
    }

    public function quotientVictoires(): float
    {
        return self::quotient($this->victoires, $this->defaites);
    }

    private static function quotient(int $numerateur, int $denominateur): float
    {
        if ($denominateur > 0) {
            return $numerateur / $denominateur;
        }

        return $numerateur > 0 ? self::INFINI : 0.0;
    }

    /** @return array<string,int|float> pour la tracabilite du classement */
    public function enTableau(): array
    {
        return [
            'parties'          => $this->parties,
            'victoires'        => $this->victoires,
            'defaites'         => $this->defaites,
            'nuls'             => $this->nuls,
            'points_rencontre' => $this->pointsRencontre,
            'manches_pour'     => $this->manchesPour,
            'manches_contre'   => $this->manchesContre,
            'diff_manches'     => $this->diffManches(),
            'points_pour'      => $this->pointsPour,
            'points_contre'    => $this->pointsContre,
        ];
    }
}
