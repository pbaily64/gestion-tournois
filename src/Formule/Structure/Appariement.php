<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Structure;

/**
 * Un appariement : « ce camp rencontre ce camp, a ce moment ».
 *
 * C'est la sortie de tous les generateurs (§3) et l'entree de la
 * planification (§C.11). Il ne porte AUCUN resultat : la structure et
 * les faits sont deux choses distinctes (§9.1). Un appariement devient
 * une `rencontre` en base, qui elle-meme porte une ou plusieurs
 * `partie` selon le systeme de rencontre (§2.3).
 *
 * Le champ `role` merite une explication. Dans un tableau, un
 * appariement n'est pas seulement « le match n°5 » : c'est « la demie
 * haute », ou « le 3e tour de la branche des perdants ». Ce role est ce
 * qui permet au moteur de router le perdant (RG-22) sans connaitre les
 * mots « consolante » ou « double elimination ».
 */
final class Appariement
{
    public const ROLE_POULE          = 'poule';
    public const ROLE_TABLEAU        = 'tableau';
    public const ROLE_BRANCHE_PERDANTS = 'branche_perdants';
    public const ROLE_GRANDE_FINALE  = 'grande_finale';
    public const ROLE_RESET          = 'reset';
    public const ROLE_PETITE_FINALE  = 'petite_finale';
    public const ROLE_CLASSEMENT     = 'classement';
    public const ROLE_BARRAGE        = 'barrage';
    public const ROLE_CROISE         = 'croise';
    public const ROLE_SUISSE         = 'suisse';
    public const ROLE_ECHELLE        = 'echelle';

    public function __construct(
        public readonly string $id,
        public readonly string $phase,
        public readonly Emplacement $a,
        public readonly Emplacement $b,
        public readonly int $tour = 1,
        public readonly int $ordre = 1,
        public readonly ?string $groupe = null,
        public readonly string $role = self::ROLE_POULE,
        public readonly ?string $libelle = null,
        public readonly ?string $enjeu = null,
    ) {
    }

    /**
     * L'appariement est-il un bye (un seul camp reel) ?
     *
     * Un bye n'est jamais joue : il est enregistre pour que le tableau
     * imprime soit complet et pour que la propagation du vainqueur soit
     * mecanique, mais il ne consomme ni table ni temps (§C.11).
     */
    public function estExempt(): bool
    {
        return $this->a->estVide() || $this->b->estVide();
    }

    /** Les deux camps sont-ils connus ? Sinon la partie n'est pas lancable. */
    public function estLancable(): bool
    {
        return $this->a->estConnu() && $this->b->estConnu();
    }

    /** Le camp qui passe d'office quand l'appariement est un bye. */
    public function beneficiaireExempt(): ?Emplacement
    {
        if (! $this->estExempt()) {
            return null;
        }

        return $this->a->estVide() ? $this->b : $this->a;
    }

    public function afficher(): string
    {
        return $this->a->afficher() . ' — ' . $this->b->afficher();
    }

    /** Meme appariement, un cote remplace. Utilise a la propagation. */
    public function avecCote(string $cote, Emplacement $emplacement): self
    {
        return new self(
            $this->id,
            $this->phase,
            $cote === 'a' ? $emplacement : $this->a,
            $cote === 'b' ? $emplacement : $this->b,
            $this->tour,
            $this->ordre,
            $this->groupe,
            $this->role,
            $this->libelle,
            $this->enjeu,
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'id'      => $this->id,
            'phase'   => $this->phase,
            'groupe'  => $this->groupe,
            'tour'    => $this->tour,
            'ordre'   => $this->ordre,
            'role'    => $this->role,
            'libelle' => $this->libelle,
            'enjeu'   => $this->enjeu,
            'a'       => $this->a->enTableau(),
            'b'       => $this->b->enTableau(),
            'exempt'  => $this->estExempt(),
        ];
    }
}
