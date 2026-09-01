<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Structure;

/**
 * Le resultat d'un generateur : la structure complete d'une phase.
 *
 * Trois informations, et rien d'autre :
 *
 *   - les GROUPES (poules, ou moitie / branche d'un tableau) ;
 *   - les TOURS, avec leur libelle affichable ;
 *   - les APPARIEMENTS, dans l'ordre de lancement souhaite.
 *
 * Les `avertissements` sont la partie honnete de la sortie : ils
 * signalent ce que le generateur a du decider a la place de
 * l'organisateur (poules de tailles inegales, exempts attribues,
 * tableau surdimensionne). Les ignorer donne un tournoi qui fonctionne ;
 * les lire donne un tournoi qui ne sera pas conteste.
 */
final class PhaseGeneree
{
    /**
     * @param string                     $phase          code de la phase
     * @param string                     $type           type_phase
     * @param array<string,list<string>> $groupes        libelle => refs
     * @param list<Appariement>          $appariements
     * @param array<int,string>          $tours          numero => libelle
     * @param list<string>               $avertissements
     * @param array<string,mixed>        $meta           donnees propres au type
     */
    public function __construct(
        public readonly string $phase,
        public readonly string $type,
        public readonly array $groupes = [],
        public readonly array $appariements = [],
        public readonly array $tours = [],
        public readonly array $avertissements = [],
        public readonly array $meta = [],
    ) {
    }

    public function nombreAppariements(): int
    {
        return count($this->appariements);
    }

    /** Les appariements reellement joues (les byes n'en sont pas). */
    public function nombreParties(): int
    {
        return count(array_filter(
            $this->appariements,
            static fn (Appariement $a): bool => ! $a->estExempt()
        ));
    }

    /** @return list<Appariement> */
    public function appariementsDuTour(int $tour): array
    {
        return array_values(array_filter(
            $this->appariements,
            static fn (Appariement $a): bool => $a->tour === $tour
        ));
    }

    /** @return list<Appariement> */
    public function appariementsDuGroupe(string $groupe): array
    {
        return array_values(array_filter(
            $this->appariements,
            static fn (Appariement $a): bool => $a->groupe === $groupe
        ));
    }

    public function appariement(string $id): ?Appariement
    {
        foreach ($this->appariements as $appariement) {
            if ($appariement->id === $id) {
                return $appariement;
            }
        }

        return null;
    }

    public function nombreTours(): int
    {
        return count($this->tours);
    }

    /** @return list<string> */
    public function libellesGroupes(): array
    {
        return array_keys($this->groupes);
    }

    public function avec(string $avertissement): self
    {
        return new self(
            $this->phase,
            $this->type,
            $this->groupes,
            $this->appariements,
            $this->tours,
            [...$this->avertissements, $avertissement],
            $this->meta,
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'phase'          => $this->phase,
            'type'           => $this->type,
            'groupes'        => $this->groupes,
            'tours'          => $this->tours,
            'appariements'   => array_map(
                static fn (Appariement $a): array => $a->enTableau(),
                $this->appariements
            ),
            'nb_parties'     => $this->nombreParties(),
            'avertissements' => $this->avertissements,
            'meta'           => $this->meta,
        ];
    }

    /**
     * Resume lisible, pour les tests et l'ecran de verification (§10.3).
     */
    public function resume(): string
    {
        $lignes = [sprintf(
            '%s (%s) : %d groupe(s), %d tour(s), %d partie(s)',
            $this->phase,
            $this->type,
            count($this->groupes),
            count($this->tours),
            $this->nombreParties(),
        )];

        foreach ($this->avertissements as $avertissement) {
            $lignes[] = '  ⚠ ' . $avertissement;
        }

        return implode("\n", $lignes);
    }
}
