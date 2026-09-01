<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Deroulement;

use RMCF\Tournois\Formule\Flux\Flux;
use RMCF\Tournois\Formule\Parametres;

/**
 * La definition complete d'un tournoi : parametres, phases, flux.
 *
 * C'est ce qu'on clone pour creer un tournoi a partir d'un prereglage
 * (§11), ce qu'on valide avant ouverture (§10.3), et ce qu'on stocke
 * avec le tournoi pour pouvoir rejouer une soiree d'il y a trois ans et
 * retrouver exactement le meme classement (§9.1).
 *
 * Aucun comportement metier ici : cet objet decrit, il n'execute pas.
 * C'est `MoteurTournoi` qui l'interprete.
 */
final class DefinitionTournoi
{
    /**
     * @param array<string,mixed>  $parametres parametres de niveau tournoi
     * @param list<DefinitionPhase> $phases
     * @param list<Flux>            $flux
     */
    public function __construct(
        public readonly string $code,
        public readonly array $parametres = [],
        public readonly array $phases = [],
        public readonly array $flux = [],
        public readonly ?string $libelle = null,
    ) {
    }

    public function libelle(): string
    {
        return $this->libelle
            ?? (is_string($this->parametres['libelle'] ?? null) ? $this->parametres['libelle'] : $this->code);
    }

    /** Les parametres du tournoi, resolus contre les defauts du catalogue. */
    public function parametres(): Parametres
    {
        return Parametres::chaine($this->parametres);
    }

    /** Les parametres d'une phase : tournoi puis surcharges de la phase. */
    public function parametresPhase(DefinitionPhase $phase): Parametres
    {
        return Parametres::chaine($this->parametres, [
            'type_phase' => $phase->type,
            ...$phase->parametres,
        ]);
    }

    /** @return list<DefinitionPhase> triees par ordre */
    public function phasesOrdonnees(): array
    {
        $phases = $this->phases;

        usort(
            $phases,
            static fn (DefinitionPhase $a, DefinitionPhase $b): int => $a->ordre <=> $b->ordre
        );

        return $phases;
    }

    public function phase(string $code): ?DefinitionPhase
    {
        foreach ($this->phases as $phase) {
            if ($phase->code === $code) {
                return $phase;
            }
        }

        return null;
    }

    /** @return list<Flux> */
    public function fluxVers(string $phase): array
    {
        return array_values(array_filter(
            $this->flux,
            static fn (Flux $f): bool => $f->phaseCible === $phase
        ));
    }

    /** @return list<Flux> */
    public function fluxDepuis(string $phase): array
    {
        return array_values(array_filter(
            $this->flux,
            static fn (Flux $f): bool => $f->phaseSource === $phase
        ));
    }

    public function avecPhase(DefinitionPhase $phase): self
    {
        return new self($this->code, $this->parametres, [...$this->phases, $phase], $this->flux, $this->libelle);
    }

    public function avecFlux(Flux $flux): self
    {
        return new self($this->code, $this->parametres, $this->phases, [...$this->flux, $flux], $this->libelle);
    }

    /** @param array<string,mixed> $surcharges */
    public function avecParametres(array $surcharges): self
    {
        return new self(
            $this->code,
            [...$this->parametres, ...$surcharges],
            $this->phases,
            $this->flux,
            $this->libelle,
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'code'       => $this->code,
            'libelle'    => $this->libelle(),
            'parametres' => $this->parametres,
            'phases'     => array_map(
                static fn (DefinitionPhase $p): array => $p->enTableau(),
                $this->phasesOrdonnees()
            ),
            'flux'       => array_map(static fn (Flux $f): array => $f->enTableau(), $this->flux),
        ];
    }

    public function enJson(): string
    {
        return json_encode(
            $this->enTableau(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: '{}';
    }
}
