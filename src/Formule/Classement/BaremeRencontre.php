<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

use InvalidArgumentException;

/**
 * Points attribues pour une rencontre, selon son issue.
 *
 * Les codes correspondent aux baremes reellement en usage :
 *
 *   2-1-0     ITTF : victoire 2, defaite jouee 1, forfait 0
 *   3-2-1-0   FRBTT : victoire 3, nul 2, defaite jouee 1, forfait 0
 *   3-1-0     victoire 3, defaite jouee 1, forfait 0
 *   1-0       simple decompte de victoires
 *
 * RG-80 — un forfait produit toujours moins qu'une defaite jouee. C'est
 * la doctrine ITTF : elle decourage l'abandon sans regle disciplinaire.
 */
final class BaremeRencontre
{
    public function __construct(
        public readonly int $victoire = 2,
        public readonly int $nul = 1,
        public readonly int $defaiteJouee = 1,
        public readonly int $forfait = 0,
    ) {
    }

    public static function depuisCode(string $code): self
    {
        return match ($code) {
            '2-1-0'    => new self(2, 1, 1, 0),
            '3-2-1-0'  => new self(3, 2, 1, 0),
            '3-1-0'    => new self(3, 1, 1, 0),
            '1-0'      => new self(1, 0, 0, 0),
            default    => self::depuisChiffres($code),
        };
    }

    /** Bareme personnalise, ecrit « v-n-d-f » ou « v-d-f ». */
    private static function depuisChiffres(string $code): self
    {
        $morceaux = array_map('intval', explode('-', $code));

        return match (count($morceaux)) {
            4       => new self($morceaux[0], $morceaux[1], $morceaux[2], $morceaux[3]),
            3       => new self($morceaux[0], $morceaux[1], $morceaux[1], $morceaux[2]),
            default => throw new InvalidArgumentException(sprintf(
                'Bareme de points de rencontre non reconnu : %s.',
                $code
            )),
        };
    }

    public function libelle(): string
    {
        return sprintf(
            'victoire %d, nul %d, défaite jouée %d, forfait %d',
            $this->victoire,
            $this->nul,
            $this->defaiteJouee,
            $this->forfait
        );
    }
}
