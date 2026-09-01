<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Deroulement;

use RMCF\Tournois\Formule\Classement\Cascade;

/**
 * La definition d'une phase, avant generation.
 *
 * Une phase n'est qu'un `type_phase` et un paquet de surcharges de
 * parametres. Elle ne contient ni appariements ni resultats : ceux-ci
 * apparaissent a la generation, et sont portes par `PhaseGeneree`.
 *
 * `conditionActivation` merite un mot (RG-21). Une phase dont la
 * condition est fausse au moment de la generation est simplement
 * ignoree, et ses flux entrants sont rediriges vers la phase active
 * suivante. C'est ce qui permet de definir UN SEUL tournoi qui
 * s'adapte au nombre d'inscrits :
 *
 *     phase « poules »      toujours
 *     phase « barrage »     si nb_qualifies > taille_tableau
 *     phase « consolante »  si nb_inscrits > 24
 *     phase « tableau »     toujours
 *
 * Sans cela il faudrait trois modeles de tournoi differents et un choix
 * a faire le soir meme, quand personne n'a le temps de le faire.
 */
final class DefinitionPhase
{
    /**
     * @param array<string,mixed> $parametres surcharges du niveau phase
     */
    public function __construct(
        public readonly string $code,
        public readonly string $type,
        public readonly int $ordre = 1,
        public readonly array $parametres = [],
        public readonly ?string $libelle = null,
        public readonly bool $obligatoire = true,
        public readonly string $conditionActivation = '',
        public readonly ?Cascade $cascadeGroupe = null,
        public readonly ?Cascade $cascadeInterGroupes = null,
    ) {
    }

    public function libelle(): string
    {
        return $this->libelle ?? match ($this->type) {
            'poules'              => 'Poules',
            'tableau'             => 'Tableau final',
            'consolante'          => 'Consolante',
            'barrage'             => 'Barrage',
            'suisse'              => 'Système suisse',
            'echelle'             => 'Montante-descendante',
            'croise'              => 'Appariement croisé',
            'classement_integral' => 'Classement intégral',
            default               => ucfirst($this->code),
        };
    }

    /** @param array<string,mixed> $surcharges */
    public function avec(array $surcharges): self
    {
        return new self(
            $this->code,
            $this->type,
            $this->ordre,
            [...$this->parametres, ...$surcharges],
            $this->libelle,
            $this->obligatoire,
            $this->conditionActivation,
            $this->cascadeGroupe,
            $this->cascadeInterGroupes,
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'code'                 => $this->code,
            'type'                 => $this->type,
            'ordre'                => $this->ordre,
            'libelle'              => $this->libelle(),
            'obligatoire'          => $this->obligatoire,
            'condition_activation' => $this->conditionActivation,
            'parametres'           => $this->parametres,
        ];
    }
}
