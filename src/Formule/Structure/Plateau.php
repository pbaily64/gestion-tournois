<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Structure;

use InvalidArgumentException;

/**
 * Le plateau : l'ensemble ordonne des entites qui entrent dans une phase.
 *
 * L'ordre est signifiant — c'est l'ordre des tetes de serie. Tous les
 * generateurs (§3) le consomment tel quel : le serpentin repartit dans
 * cet ordre, le tableau place les tetes de serie dans cet ordre, le
 * suisse coupe le plateau en deux dans cet ordre.
 *
 * Consequence pratique : la seule chose qui distingue « placement par
 * classement » de « placement par tirage au sort » est l'ordre du
 * plateau qu'on donne au generateur. Il n'y a donc aucune option de
 * tirage dans les generateurs eux-memes.
 */
final class Plateau
{
    /** @var list<Entite> */
    private readonly array $entites;

    /** @param list<Entite> $entites */
    public function __construct(array $entites)
    {
        $vues = [];

        foreach ($entites as $entite) {
            if (isset($vues[$entite->ref])) {
                throw new InvalidArgumentException(
                    "Entite en double dans le plateau : {$entite->ref}"
                );
            }
            $vues[$entite->ref] = true;
        }

        $this->entites = array_values($entites);
    }

    public static function vide(): self
    {
        return new self([]);
    }

    /**
     * Construit un plateau a partir de refs simples, rang = position.
     *
     * Raccourci de test et de script : `Plateau::depuisRefs(['A','B','C'])`.
     *
     * @param list<string> $refs
     */
    public static function depuisRefs(array $refs): self
    {
        $entites = [];
        $rang    = 1;

        foreach ($refs as $ref) {
            $entites[] = new Entite($ref, $ref, $rang++);
        }

        return new self($entites);
    }

    /** @return list<Entite> */
    public function entites(): array
    {
        return $this->entites;
    }

    /** @return list<string> */
    public function refs(): array
    {
        return array_map(static fn (Entite $e): string => $e->ref, $this->entites);
    }

    public function effectif(): int
    {
        return count($this->entites);
    }

    public function estVide(): bool
    {
        return $this->entites === [];
    }

    public function entite(string $ref): ?Entite
    {
        foreach ($this->entites as $entite) {
            if ($entite->ref === $ref) {
                return $entite;
            }
        }

        return null;
    }

    public function contient(string $ref): bool
    {
        return $this->entite($ref) !== null;
    }

    /**
     * Trie par rang de tete de serie croissant (1 = meilleur).
     *
     * Les entites sans rang (0) sont rejetees en fin de plateau : elles
     * n'ont pas de classement gele exploitable, ce qui est le cas normal
     * d'un non-classe inscrit au dernier moment.
     */
    public function parRang(): self
    {
        $entites = $this->entites;

        usort($entites, static function (Entite $a, Entite $b): int {
            $ra = $a->rang > 0 ? $a->rang : PHP_INT_MAX;
            $rb = $b->rang > 0 ? $b->rang : PHP_INT_MAX;

            return $ra <=> $rb;
        });

        return new self($entites);
    }

    /**
     * Renumerote les rangs selon la position courante.
     *
     * Indispensable a l'entree d'un tableau : les qualifies arrivent avec
     * leur rang de poule, le generateur a besoin de tetes de serie
     * contigues 1..n.
     */
    public function renumerote(): self
    {
        $entites = [];
        $rang    = 1;

        foreach ($this->entites as $entite) {
            $entites[] = $entite->avecRang($rang++);
        }

        return new self($entites);
    }

    /** @param callable(Entite):bool $filtre */
    public function filtrer(callable $filtre): self
    {
        return new self(array_values(array_filter($this->entites, $filtre)));
    }

    public function fusionner(self $autre): self
    {
        return new self([...$this->entites, ...$autre->entites]);
    }

    /** Les `n` premieres entites du plateau. */
    public function premieres(int $n): self
    {
        return new self(array_slice($this->entites, 0, max(0, $n)));
    }

    /** Tout sauf les `n` premieres. */
    public function saufPremieres(int $n): self
    {
        return new self(array_slice($this->entites, max(0, $n)));
    }

    /**
     * Melange le plateau de facon reproductible.
     *
     * La graine est stockee avec le tournoi : un tirage au sort doit
     * pouvoir etre rejoue a l'identique trois ans plus tard (§9.1). Un
     * `shuffle()` non graine rendrait le tournoi non reproductible.
     */
    public function melanger(int $graine): self
    {
        $entites = $this->entites;
        mt_srand($graine);

        for ($i = count($entites) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$entites[$i], $entites[$j]] = [$entites[$j], $entites[$i]];
        }

        mt_srand();

        return new self($entites);
    }

    /**
     * Regroupe les entites par groupe d'origine.
     *
     * @return array<string,list<Entite>>
     */
    public function parOrigine(): array
    {
        $groupes = [];

        foreach ($this->entites as $entite) {
            $groupes[$entite->origine ?? ''][] = $entite;
        }

        return $groupes;
    }
}
