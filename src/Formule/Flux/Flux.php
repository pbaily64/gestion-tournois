<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Flux;

/**
 * Un flux de qualification : une phase source, une phase cible, une regle.
 *
 * C'est la table `flux_qualification` du §9.5, sous forme d'objet. Elle
 * decrit a elle seule tout ce qui se passe ENTRE les phases :
 *
 *     poules  --place_exacte 1..2-->  tableau      (qualification)
 *     poules  --non_qualifies----->   consolante   (consolante)
 *     tableau --perdants_tour 1--->   consolante   (autre consolante)
 *     tableau --elimines 1 defaite->  repechage    (double elimination)
 *     poules  --montants---------->   division sup (criterium)
 *
 * Aucune de ces cinq lignes n'a de code dedie. C'est exactement ce que
 * le document annonce : « il n'y a pas quatre mecanismes, il y en a un ».
 *
 * L'ORDRE compte. Deux flux peuvent selectionner la meme entite ; c'est
 * celui de plus petit `ordre` qui gagne (RG-31). C'est ce qui permet
 * d'ecrire « les 2 premiers au tableau, tout le reste en consolante »
 * sans jamais enumerer les places restantes.
 */
final class Flux
{
    public const SOURCE_INSCRIPTIONS = 'inscriptions';

    public const SURNOMBRE_BARRAGE   = 'barrage';
    public const SURNOMBRE_TRONQUER  = 'tronquer';
    public const SURNOMBRE_ELARGIR   = 'elargir_cible';

    public const SOUS_NOMBRE_EXEMPTS  = 'exempts';
    public const SOUS_NOMBRE_REPECHER = 'repecher';
    public const SOUS_NOMBRE_REDUIRE  = 'reduire_cible';

    public function __construct(
        public readonly string $phaseSource,
        public readonly string $phaseCible,
        public readonly Selecteur $selecteur,
        public readonly int|string|null $parametre = null,
        public readonly int $ordre = 1,
        public readonly int|string $tourEntreeCible = 'auto',
        public readonly string $modePlacement = 'croise',
        public readonly ?string $regleOrdre = null,
        public readonly ?int $capaciteMax = null,
        public readonly string $siSurnombre = self::SURNOMBRE_BARRAGE,
        public readonly string $siSousNombre = self::SOUS_NOMBRE_EXEMPTS,
        /** @var list<string> designation manuelle */
        public readonly array $designes = [],
    ) {
    }

    /** Cle stable, utilisee comme reference d'emplacement `qualifie`. */
    public function cle(): string
    {
        return sprintf(
            '%s>%s:%s%s',
            $this->phaseSource,
            $this->phaseCible,
            $this->selecteur->value,
            $this->parametre !== null ? ':' . $this->parametre : ''
        );
    }

    /** Le parametre, interprete comme un entier (place, tour, n…). */
    public function parametreEntier(int $defaut = 1): int
    {
        if (is_int($this->parametre)) {
            return $this->parametre;
        }

        if (is_string($this->parametre) && $this->parametre !== '' && ctype_digit($this->parametre)) {
            return (int) $this->parametre;
        }

        return $defaut;
    }

    /**
     * Le parametre, interprete comme un intervalle « k1-k2 ».
     *
     * @return array{0:int,1:int}
     */
    public function parametreIntervalle(): array
    {
        if (is_string($this->parametre) && str_contains($this->parametre, '-')) {
            [$a, $b] = array_map('intval', explode('-', $this->parametre, 2));

            return [min($a, $b), max($a, $b)];
        }

        $k = $this->parametreEntier();

        return [$k, $k];
    }

    public function depuisInscriptions(): bool
    {
        return $this->phaseSource === self::SOURCE_INSCRIPTIONS;
    }

    public function description(): string
    {
        $source = $this->depuisInscriptions() ? 'Inscriptions' : $this->phaseSource;
        $detail = $this->parametre !== null ? sprintf(' (%s)', (string) $this->parametre) : '';

        return sprintf(
            '%s → %s : %s%s',
            $source,
            $this->phaseCible,
            $this->selecteur->libelle(),
            $detail
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'phase_source'      => $this->phaseSource,
            'phase_cible'       => $this->phaseCible,
            'selecteur'         => $this->selecteur->value,
            'parametre'         => $this->parametre,
            'ordre'             => $this->ordre,
            'tour_entree_cible' => $this->tourEntreeCible,
            'mode_placement'    => $this->modePlacement,
            'regle_ordre'       => $this->regleOrdre,
            'capacite_max'      => $this->capaciteMax,
            'si_surnombre'      => $this->siSurnombre,
            'si_sous_nombre'    => $this->siSousNombre,
        ];
    }
}
