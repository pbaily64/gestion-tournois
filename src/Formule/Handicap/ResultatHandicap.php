<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Handicap;

/**
 * Handicap calcule pour une partie.
 *
 * C'est un FAIT, pas une donnee derivee : la valeur a ete imprimee sur
 * la feuille de match, les joueurs l'ont vue, elle se stocke (section
 * 9.3). Recalculer un handicap apres coup, avec des classements qui ont
 * bouge, donnerait une autre valeur et fausserait la relecture d'une
 * soiree passee.
 *
 * Le score encode reste celui du marquoir, avance comprise : un joueur
 * qui demarre a 6 et l'emporte 11-8 a marque cinq points reels, seul le
 * 11-8 est enregistre. C'est la convention deja retenue par le module.
 */
final class ResultatHandicap
{
    public function __construct(
        public readonly int $valeur,
        public readonly ?string $beneficiaire,
        public readonly float $ecart,
        public readonly float $forceA,
        public readonly float $forceB,
        public readonly string $application = 'par_manche',
    ) {
    }

    public function sansHandicap(): bool
    {
        return $this->valeur === 0 || $this->beneficiaire === null;
    }

    /**
     * Points de depart de chaque camp dans une manche.
     *
     * @return array{a:int, b:int}
     */
    public function pointsDeDepart(): array
    {
        return [
            'a' => $this->beneficiaire === 'a' ? $this->valeur : 0,
            'b' => $this->beneficiaire === 'b' ? $this->valeur : 0,
        ];
    }

    /** Mention portee sur la feuille de match. */
    public function mention(): string
    {
        if ($this->sansHandicap()) {
            return 'sans handicap';
        }

        return sprintf(
            '%d point%s d\'avance pour %s (%s)',
            $this->valeur,
            $this->valeur > 1 ? 's' : '',
            $this->beneficiaire === 'a' ? 'le camp A' : 'le camp B',
            $this->application === 'par_manche' ? 'par manche' : 'une seule fois'
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'handicap_valeur'       => $this->valeur,
            'handicap_beneficiaire' => $this->beneficiaire,
            'handicap_ecart'        => $this->ecart,
            'handicap_force_a'      => $this->forceA,
            'handicap_force_b'      => $this->forceB,
            'handicap_application'  => $this->application,
        ];
    }
}
