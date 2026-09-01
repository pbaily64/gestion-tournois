<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Structure;

/**
 * Une entite en lice : ce qui est classe.
 *
 * Selon `type_entite`, c'est un joueur, une paire, un duo ou une equipe
 * (§2). Le moteur de generation n'a pas besoin de savoir lequel : il ne
 * manipule que des references opaques. C'est ce qui permet d'utiliser le
 * meme generateur de poules pour un simple et pour un championnat par
 * equipes.
 *
 * DISTINCTION ESSENTIELLE (§2.5, §2.6, RG-14) — l'entite classee n'est
 * pas forcement le camp qui joue. En Americano, les paires changent a
 * chaque tour mais le classement est individuel : l'entite est alors le
 * joueur, et la composition du camp est portee par la rencontre. Cette
 * classe ne decrit donc QUE le sujet du classement.
 *
 * Le rang porte ici est le rang de tete de serie (1 = meilleur), deja
 * resolu a partir du classement gele (RG-02). Le moteur ne lit jamais un
 * classement courant.
 */
final class Entite
{
    /**
     * @param string      $ref            identifiant opaque (id d'inscription)
     * @param string      $libelle        intitule affiche
     * @param int         $rang           tete de serie, 1 = meilleur
     * @param int         $classementGele echelon gele au sens du referentiel
     * @param string|null $origine        groupe de provenance (poule source)
     * @param int         $viesRestantes  §3.4 — compteur de vies
     * @param string|null $club           pour `criteres_separation`
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $libelle,
        public readonly int $rang = 0,
        public readonly int $classementGele = 0,
        public readonly ?string $origine = null,
        public readonly int $viesRestantes = 1,
        public readonly ?string $club = null,
        public readonly ?string $famille = null,
        /**
         * Place a pourvoir, dont le titulaire n'est pas encore designe.
         *
         * Une entite provisoire occupe une place dans la structure sans
         * pouvoir jouer : c'est le cas des qualifies d'un barrage qui
         * n'a pas encore eu lieu. Le drapeau est lu par
         * `Emplacement::entite()`, qui produit alors une place a
         * pourvoir plutot qu'un camp connu — sans quoi la table de
         * marque appellerait un joueur contre un adversaire inexistant.
         */
        public readonly bool $provisoire = false,
    ) {
    }

    /** Une place a pourvoir, en attente de son titulaire. */
    public static function aPourvoir(string $ref, string $libelle): self
    {
        return new self($ref, $libelle, 0, 0, null, 1, null, null, true);
    }

    /**
     * Meme entite, avec une provenance mise a jour.
     *
     * Utilise par le moteur de flux : un qualifie qui entre dans un
     * tableau conserve la memoire de sa poule d'origine, ce dont
     * `separer_meme_poule` a besoin (RG-34).
     */
    public function issuDe(string $groupe): self
    {
        return new self(
            $this->ref,
            $this->libelle,
            $this->rang,
            $this->classementGele,
            $groupe,
            $this->viesRestantes,
            $this->club,
            $this->famille,
            $this->provisoire,
        );
    }

    /**
     * Meme entite, avec un rang de tete de serie recalcule.
     *
     * Le rang change d'une phase a l'autre : en poules c'est le
     * classement officiel, a l'entree du tableau c'est la place obtenue
     * en poule. La distinction est portee par le flux, pas par l'entite.
     */
    public function avecRang(int $rang): self
    {
        return new self(
            $this->ref,
            $this->libelle,
            $rang,
            $this->classementGele,
            $this->origine,
            $this->viesRestantes,
            $this->club,
            $this->famille,
            $this->provisoire,
        );
    }

    /** Consomme une vie (§3.4). */
    public function apresDefaite(): self
    {
        return new self(
            $this->ref,
            $this->libelle,
            $this->rang,
            $this->classementGele,
            $this->origine,
            max(0, $this->viesRestantes - 1),
            $this->club,
            $this->famille,
            $this->provisoire,
        );
    }

    public function eliminee(): bool
    {
        return $this->viesRestantes <= 0;
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'ref'             => $this->ref,
            'libelle'         => $this->libelle,
            'rang'            => $this->rang,
            'classement_gele' => $this->classementGele,
            'origine'         => $this->origine,
            'vies_restantes'  => $this->viesRestantes,
            'club'            => $this->club,
            'provisoire'      => $this->provisoire,
        ];
    }
}
