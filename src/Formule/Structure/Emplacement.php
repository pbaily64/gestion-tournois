<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Structure;

/**
 * Un cote d'appariement : soit une entite connue, soit une provenance.
 *
 * C'est la piece qui permet de generer la STRUCTURE COMPLETE d'un
 * tournoi avant qu'aucune partie ne soit jouee. Le deuxieme tour d'un
 * tableau existe des la generation, avec ses deux cotes exprimes comme
 * « vainqueur du match 1 » et « vainqueur du match 2 ».
 *
 * Sans cela il faudrait regenerer le tableau apres chaque tour, ce qui
 * interdit d'imprimer la feuille de tableau a l'avance — exigence
 * explicite du club, et raison pour laquelle le systeme suisse est
 * signale comme couteux (§3.6, §12.4) : lui seul ne peut pas etre
 * exprime avec des provenances.
 *
 * Le cas `vide` est le bye (exempt) : l'adversaire n'existe pas, le
 * cote oppose passe au tour suivant sans jouer.
 */
final class Emplacement
{
    public const ENTITE    = 'entite';
    public const VAINQUEUR = 'vainqueur';
    public const PERDANT   = 'perdant';
    public const QUALIFIE  = 'qualifie';
    public const VIDE      = 'vide';

    private function __construct(
        public readonly string $nature,
        public readonly ?string $reference = null,
        public readonly ?string $libelle = null,
    ) {
    }

    public static function entite(Entite $entite): self
    {
        return new self(self::ENTITE, $entite->ref, $entite->libelle);
    }

    public static function ref(string $ref, ?string $libelle = null): self
    {
        return new self(self::ENTITE, $ref, $libelle ?? $ref);
    }

    /** Le vainqueur d'un appariement anterieur, designe par son id. */
    public static function vainqueurDe(string $appariement, ?string $libelle = null): self
    {
        return new self(self::VAINQUEUR, $appariement, $libelle ?? "Vainqueur {$appariement}");
    }

    /** Le perdant d'un appariement anterieur (consolante, branche perdants). */
    public static function perdantDe(string $appariement, ?string $libelle = null): self
    {
        return new self(self::PERDANT, $appariement, $libelle ?? "Perdant {$appariement}");
    }

    /**
     * Une place a pourvoir par un flux entrant.
     *
     * Exemple : « 1er de la poule C ». La reference est la cle du flux,
     * resolue par le MoteurFlux quand la phase source est close.
     */
    public static function qualifie(string $cle, string $libelle): self
    {
        return new self(self::QUALIFIE, $cle, $libelle);
    }

    public static function vide(): self
    {
        return new self(self::VIDE, null, 'Exempt');
    }

    public function estConnu(): bool
    {
        return $this->nature === self::ENTITE;
    }

    public function estVide(): bool
    {
        return $this->nature === self::VIDE;
    }

    /** Depend-il du resultat d'un appariement anterieur ? */
    public function estDifferé(): bool
    {
        return $this->nature === self::VAINQUEUR || $this->nature === self::PERDANT;
    }

    public function afficher(): string
    {
        return $this->libelle ?? $this->reference ?? '—';
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'nature'    => $this->nature,
            'reference' => $this->reference,
            'libelle'   => $this->libelle,
        ];
    }
}
