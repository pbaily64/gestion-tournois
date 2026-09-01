<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Flux;

use RMCF\Tournois\Formule\Structure\Entite;

/**
 * Source de resultats en memoire.
 *
 * Deux usages, et le second justifie a lui seul son existence :
 *
 *   1. les tests — on decrit un classement de poules en trois lignes et
 *      on verifie que le flux place les bonnes personnes au bon endroit ;
 *   2. la SIMULATION — l'organisateur veut savoir a quoi ressemblera son
 *      tableau si telle poule finit dans tel ordre. Sans une source
 *      alimentable a la main, cette question exigerait d'ecrire de faux
 *      resultats en base.
 */
final class ResultatsEnMemoire implements SourceResultats
{
    /** @var array<string,array<string,list<string>>> phase => groupe => classement */
    private array $classements = [];

    /** @var array<string,list<string>> phase => classement inter-groupes */
    private array $globaux = [];

    /** @var array<string,array<int,list<string>>> phase => tour => perdants */
    private array $perdants = [];

    /** @var array<string,array<int,list<string>>> phase => tour => vainqueurs */
    private array $vainqueurs = [];

    /** @var array<string,array<string,int>> phase => entite => defaites */
    private array $defaites = [];

    /** @var array<string,Entite> */
    private array $entites = [];

    /** @var array<string,bool> */
    private array $closes = [];

    /** @param list<Entite> $entites */
    public function __construct(array $entites = [])
    {
        foreach ($entites as $entite) {
            $this->entites[$entite->ref] = $entite;
        }
    }

    /** @param list<Entite> $entites */
    public function ajouterEntites(array $entites): self
    {
        foreach ($entites as $entite) {
            $this->entites[$entite->ref] = $entite;
        }

        return $this;
    }

    /**
     * Declare le classement d'un groupe. Cloture la phase par defaut.
     *
     * @param list<string> $classement
     */
    public function classer(string $phase, string $groupe, array $classement): self
    {
        $this->classements[$phase][$groupe] = array_values($classement);
        $this->closes[$phase] ??= true;

        foreach ($classement as $ref) {
            if (! isset($this->entites[$ref])) {
                $this->entites[$ref] = new Entite($ref, $ref);
            }

            $this->entites[$ref] = $this->entites[$ref]->issuDe($groupe);
        }

        return $this;
    }

    /** @param list<string> $classement */
    public function classerGlobal(string $phase, array $classement): self
    {
        $this->globaux[$phase] = array_values($classement);
        $this->closes[$phase] ??= true;

        return $this;
    }

    /**
     * @param list<string> $vainqueurs
     * @param list<string> $perdants
     */
    public function tour(string $phase, int $tour, array $vainqueurs, array $perdants): self
    {
        $this->vainqueurs[$phase][$tour] = array_values($vainqueurs);
        $this->perdants[$phase][$tour]   = array_values($perdants);

        foreach ($perdants as $ref) {
            $this->defaites[$phase][$ref] = ($this->defaites[$phase][$ref] ?? 0) + 1;
        }

        return $this;
    }

    public function cloturer(string $phase, bool $close = true): self
    {
        $this->closes[$phase] = $close;

        return $this;
    }

    // --- lecture -----------------------------------------------------

    public function groupes(string $phase): array
    {
        return array_keys($this->classements[$phase] ?? []);
    }

    public function classementGroupe(string $phase, string $groupe): array
    {
        return $this->classements[$phase][$groupe] ?? [];
    }

    public function classementGlobal(string $phase): array
    {
        if (isset($this->globaux[$phase])) {
            return $this->globaux[$phase];
        }

        // Repli : on entrelace les classements de groupe — tous les 1ers,
        // puis tous les 2es, etc. C'est l'ordre par defaut d'un classement
        // inter-poules avant application d'une cascade dediee (§7.6).
        $parPlace = [];

        foreach ($this->classements[$phase] ?? [] as $classement) {
            foreach ($classement as $place => $ref) {
                $parPlace[$place][] = $ref;
            }
        }

        ksort($parPlace);
        $global = [];

        foreach ($parPlace as $refs) {
            $global = [...$global, ...$refs];
        }

        return $global;
    }

    public function perdantsTour(string $phase, int $tour): array
    {
        return $this->perdants[$phase][$tour] ?? [];
    }

    public function vainqueursTour(string $phase, int $tour): array
    {
        return $this->vainqueurs[$phase][$tour] ?? [];
    }

    public function defaites(string $phase, string $entite): int
    {
        return $this->defaites[$phase][$entite] ?? 0;
    }

    public function entite(string $ref): ?Entite
    {
        return $this->entites[$ref] ?? null;
    }

    public function estClose(string $phase): bool
    {
        return $this->closes[$phase] ?? false;
    }

    /** Toutes les entites connues d'une phase, dans l'ordre du classement. */
    public function toutes(string $phase): array
    {
        return $this->classementGlobal($phase);
    }
}
