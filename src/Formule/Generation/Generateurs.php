<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Generation;

use InvalidArgumentException;

/**
 * Fabrique : `type_phase` -> generateur.
 *
 * Le tableau ci-dessous est la traduction complete de l'axe B (§3). Il
 * y a sept briques d'appariement et huit types de phase, parce que
 * `consolante` et `tableau` partagent la meme brique : une consolante
 * n'est qu'un tableau alimente par un flux `perdants_tour` (RG-22).
 *
 * C'est la meme observation qui rend `classement_integral` inutile en
 * tant que code separe — c'est un tableau dont `defaites_tolerees` vaut
 * l'infini. On le garde comme type de phase distinct pour l'ergonomie
 * de l'ecran de configuration, pas pour le moteur.
 */
final class Generateurs
{
    /** Types de phase reconnus, y compris `croise` (lacune C.12 comblee). */
    public const TYPES = [
        'poules',
        'tableau',
        'consolante',
        'classement_integral',
        'barrage',
        'suisse',
        'echelle',
        'croise',
    ];

    /**
     * @param list<array{0:string,1:string}> $confrontationsAnterieures
     */
    public function __construct(
        private readonly int $graine = 0,
        private readonly array $confrontationsAnterieures = [],
    ) {
    }

    public function pour(string $typePhase): Generateur
    {
        return match ($typePhase) {
            'poules' => new GenerateurPoules($this->confrontationsAnterieures, $this->graine),
            'tableau', 'consolante', 'classement_integral'
                     => new GenerateurTableau($typePhase, $this->graine),
            'barrage' => new GenerateurBarrage($this->graine),
            'suisse'  => new GenerateurSuisse($this->graine),
            'echelle' => new GenerateurEchelle(),
            'croise'  => new GenerateurCroise(),
            default   => throw new InvalidArgumentException(
                "Type de phase inconnu : {$typePhase}. Types admis : " . implode(', ', self::TYPES)
            ),
        };
    }

    public function existe(string $typePhase): bool
    {
        return in_array($typePhase, self::TYPES, true);
    }
}
