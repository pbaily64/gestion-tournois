<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Flux;

use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * Le resultat de la resolution des flux vers UNE phase cible.
 *
 * Trois informations, dont la troisieme est celle qui compte :
 *
 *   - `entrants`   : le plateau, dans l'ordre de placement demande ;
 *   - `origines`   : d'ou vient chacun, pour la separation (RG-34) ;
 *   - `barrageRequis` : les entites en surnombre.
 *
 * Ce dernier point est le mecanisme des « meilleurs deuxiemes » (RG-33).
 * Quand plus de candidats se presentent qu'il n'y a de places, le moteur
 * ne tranche pas tout seul et ne tronque pas en silence : il remonte la
 * liste des barragistes et le nombre de places, a charge du moteur de
 * tournoi d'intercaler une phase de barrage. C'est exactement le
 * fonctionnement du MbN, ou le barrage s'insere entre les poules et le
 * tableau, et celui des championnats du monde par equipes.
 */
final class ResultatFlux
{
    /**
     * @param list<string>        $refusees      entites ecartees (troncature)
     * @param list<string>        $barrageRequis entites a departager
     * @param list<string>        $notes
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly string $phaseCible,
        public readonly Plateau $entrants,
        public readonly array $refusees = [],
        public readonly array $barrageRequis = [],
        public readonly int $placesRestantes = 0,
        public readonly array $notes = [],
        public readonly array $meta = [],
    ) {
    }

    public function effectif(): int
    {
        return $this->entrants->effectif();
    }

    public function exigeBarrage(): bool
    {
        return $this->barrageRequis !== [];
    }

    public function avec(string $note): self
    {
        return new self(
            $this->phaseCible,
            $this->entrants,
            $this->refusees,
            $this->barrageRequis,
            $this->placesRestantes,
            [...$this->notes, $note],
            $this->meta,
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'phase_cible'      => $this->phaseCible,
            'entrants'         => $this->entrants->refs(),
            'refusees'         => $this->refusees,
            'barrage_requis'   => $this->barrageRequis,
            'places_restantes' => $this->placesRestantes,
            'notes'            => $this->notes,
            'meta'             => $this->meta,
        ];
    }
}
