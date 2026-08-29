<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

use InvalidArgumentException;

/**
 * Qualification a l'issue des poules : barrages et composition des
 * tableaux.
 *
 * Soit N le nombre de joueurs qui poursuivent la phase. L'organisateur
 * decide par ailleurs de l'ouverture ou non d'un tableau de consolation.
 * Quatre situations se presentent (section 4.6) :
 *
 *   N < 17                    tableau final seul, pas de barrage
 *   N > 16, sans consolation  barrage, cible 16
 *   N <= 32, avec consolation pas de barrage
 *   N > 32, avec consolation  barrage, cible 32
 *
 * LE BARRAGE
 *
 * excedent = N - cible. Les 2 x excedent derniers qualifies disputent
 * un match a elimination directe, en appariement croise : le meilleur
 * de ce groupe contre le moins bon. Les vainqueurs completent la cible.
 *
 * Les vainqueurs de barrage rejoignent le tableau de CONSOLATION
 * lorsqu'il existe, le tableau FINAL sinon.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class Qualification
{
    public const CIBLE_SANS_CONSOLATION = 16;
    public const CIBLE_AVEC_CONSOLATION = 32;

    /** Taille d'un tableau : seize places, de 1/8 a la finale. */
    public const TAILLE_TABLEAU = 16;

    /**
     * @param list<array{0:int,1:int}> $barrages couples de places, meilleure d'abord
     */
    private function __construct(
        public readonly int $effectif,
        public readonly bool $avecConsolation,
        public readonly int $cible,
        public readonly int $excedent,
        public readonly array $barrages,
    ) {
    }

    public static function pour(int $effectif, bool $avecConsolation): self
    {
        if ($effectif < 0) {
            throw new InvalidArgumentException('Effectif negatif.');
        }

        // Sans consolation, la cible est 16 ; avec, elle est 32. En
        // dessous de 17 joueurs, un seul tableau suffit de toute facon.
        $cible = ($avecConsolation && $effectif > self::CIBLE_SANS_CONSOLATION)
            ? self::CIBLE_AVEC_CONSOLATION
            : self::CIBLE_SANS_CONSOLATION;

        $excedent = max(0, $effectif - $cible);

        return new self($effectif, $avecConsolation, $cible, $excedent, self::appariements($cible, $excedent));
    }

    /** Un barrage est-il necessaire ? */
    public function avecBarrage(): bool
    {
        return $this->excedent > 0;
    }

    /**
     * Appariement croise des joueurs a departager.
     *
     * Pour un excedent E et une cible C, les places C-E+1 a C+E sont
     * concernees. Le i-eme match oppose la place C-E+i a la place
     * C+E+1-i : le meilleur du groupe rencontre le moins bon, le
     * deuxieme rencontre l'avant-dernier, et ainsi de suite.
     *
     * @return list<array{0:int,1:int}>
     */
    private static function appariements(int $cible, int $excedent): array
    {
        $matchs = [];

        for ($i = 1; $i <= $excedent; $i++) {
            $matchs[] = [$cible - $excedent + $i, $cible + $excedent + 1 - $i];
        }

        return $matchs;
    }

    /**
     * Places du classement general qualifiees d'office, sans barrage.
     *
     * @return list<int>
     */
    public function qualifiesDirects(): array
    {
        $derniere = $this->cible - $this->excedent;

        return $derniere < 1 ? [] : range(1, min($derniere, $this->effectif));
    }

    /**
     * Places disputant le barrage, dans l'ordre du classement.
     *
     * @return list<int>
     */
    public function barragistes(): array
    {
        if (!$this->avecBarrage()) {
            return [];
        }

        return range($this->cible - $this->excedent + 1, min($this->cible + $this->excedent, $this->effectif));
    }

    /** Ou vont les vainqueurs de barrage ? */
    public function destinationBarrage(): string
    {
        return $this->avecConsolation ? 'consolation' : 'tableau_final';
    }

    /**
     * Places qui alimentent le tableau final : les seize premieres.
     *
     * @return list<int>
     */
    public function placesTableauFinal(): array
    {
        return range(1, min(self::TAILLE_TABLEAU, max($this->effectif, 1)));
    }

    /**
     * Places qui alimentent la consolante : les seize suivantes.
     *
     * @return list<int>
     */
    public function placesConsolation(): array
    {
        if (!$this->avecConsolation || $this->effectif <= self::TAILLE_TABLEAU) {
            return [];
        }

        $fin = min(2 * self::TAILLE_TABLEAU, $this->cible);

        return range(self::TAILLE_TABLEAU + 1, $fin);
    }

    /** Resume destine a l'organisateur avant generation. */
    public function resume(): string
    {
        if (!$this->avecBarrage()) {
            return sprintf(
                '%d joueur(s) : %s, aucun barrage.',
                $this->effectif,
                $this->avecConsolation && $this->effectif > self::TAILLE_TABLEAU
                    ? 'tableau final et consolante'
                    : 'tableau final seul'
            );
        }

        return sprintf(
            '%d joueur(s) pour %d places : %d match(s) de barrage entre les places %d et %d. '
            . 'Les vainqueurs rejoignent %s.',
            $this->effectif,
            $this->cible,
            $this->excedent,
            $this->cible - $this->excedent + 1,
            $this->cible + $this->excedent,
            $this->destinationBarrage() === 'consolation' ? 'la consolante' : 'le tableau final'
        );
    }
}
