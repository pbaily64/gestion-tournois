<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

/**
 * Une ligne de classement calcule.
 *
 * Elle porte le rang, mais surtout LE CRITERE QUI A DEPARTAGE et la
 * valeur de tous les criteres evalues (RG-56, section 9.6). C'est ce
 * qui permet d'afficher, au survol d'un rang, « departage au ratio de
 * manches (0,714 contre 0,667) » — et c'est ce qui desamorce la
 * quasi-totalite des contestations de fin de soiree.
 *
 * Le classement etant toujours DERIVE (RG-55), cet objet est un
 * resultat de calcul, jamais une donnee saisie.
 */
final class Rang
{
    /**
     * @param array<string,float> $valeurs       critere => valeur evaluee
     * @param array<string,int|float> $bilan     bilan chiffre sur la poule
     */
    public function __construct(
        public readonly string $entite,
        public readonly int $rang,
        public readonly ?Critere $critereDecisif,
        public readonly bool $exAequo,
        public readonly bool $barrageRequis,
        public readonly array $valeurs,
        public readonly array $bilan,
    ) {
    }

    /**
     * Phrase affichable expliquant le rang.
     *
     * Volontairement courte : elle tient dans une infobulle.
     */
    public function explication(): string
    {
        if ($this->barrageRequis) {
            return 'égalité à départager par un match de barrage';
        }

        if ($this->exAequo) {
            return 'égalité qu\'aucun critère ne tranche';
        }

        if ($this->critereDecisif === null) {
            return 'classé sans départage nécessaire';
        }

        $valeur = $this->valeurs[$this->critereDecisif->value] ?? null;

        if ($valeur === null) {
            return sprintf('départagé sur : %s', $this->critereDecisif->libelle());
        }

        return sprintf(
            'départagé sur %s (%s)',
            mb_strtolower($this->critereDecisif->libelle()),
            self::formater($valeur)
        );
    }

    /** @return array<string,mixed> tel que stocke dans classement_calcule */
    public function enTableau(): array
    {
        return [
            'entite'          => $this->entite,
            'rang'            => $this->rang,
            'critere_decisif' => $this->critereDecisif?->value,
            'ex_aequo'        => $this->exAequo,
            'barrage_requis'  => $this->barrageRequis,
            'valeurs'         => $this->valeurs,
            'bilan'           => $this->bilan,
        ];
    }

    private static function formater(float $valeur): string
    {
        if ($valeur >= Bilan::INFINI) {
            return 'aucune défaite';
        }

        return abs($valeur - round($valeur)) < 1e-9
            ? (string) (int) round($valeur)
            : number_format($valeur, 3, ',', ' ');
    }
}
