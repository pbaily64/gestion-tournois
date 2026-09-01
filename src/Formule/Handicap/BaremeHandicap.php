<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Handicap;

use InvalidArgumentException;
use RMCF\Tournois\Formule\Expression;
use RMCF\Tournois\Formule\Parametres;

/**
 * Bareme de handicap (annexe C.9).
 *
 * Le handicap rend une partie disputable entre joueurs de niveaux
 * eloignes : le plus faible commence la manche avec une avance de
 * points. Il se calcule a partir de l'ECART de force entre les deux
 * camps, jamais des classements pris isolement.
 *
 * DEUX MODES DE CALCUL
 *
 *   formule  une expression sur la variable e, l'ecart :
 *            sign(e)*min(8; abs(e)/2+1) — le bareme du Mickey By Night
 *   table    une correspondance ecart -> handicap saisie a la main,
 *            pour les baremes federaux qui ne suivent aucune loi simple
 *
 * TROIS PIEGES, ET COMMENT ILS SONT TRAITES ICI
 *
 * RG-71 — le SENS DE L'ECHELLE doit etre explicite. A l'AFTT, un rang
 * eleve designe un joueur fort (NC vaut 0, A vaut 17) ; dans d'autres
 * referentiels c'est l'inverse. Un signe inverse ici, et le handicap
 * profite systematiquement au mauvais camp sans que rien ne plante.
 *
 * RG-72 — l'ARRONDI EST DIFFERE : en methode « meilleur ajuste », les
 * forces de paires se calculent avec leurs decimales et ne s'arrondissent
 * qu'apres soustraction. Arrondir chaque force avant de soustraire
 * introduit un biais d'un point sur une paire sur deux.
 *
 * RG-77 — un plafond superieur a la moitie des points de la manche rend
 * le format inadapte : le validateur avertit, il ne bloque pas.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class BaremeHandicap
{
    public const SENS_RANG_HAUT_FORT   = 'rang_haut_fort';
    public const SENS_RANG_HAUT_FAIBLE = 'rang_haut_faible';

    public const DOUBLE_MOYENNE     = 'moyenne';
    public const DOUBLE_FORT_AJUSTE = 'fort_ajuste';
    public const DOUBLE_SOMME       = 'somme';
    public const DOUBLE_DEDIE       = 'classement_dedie';

    /**
     * @param array<int,int|float> $table              ecart => handicap
     * @param array<int,int|float> $fonctionAjustement ecart intra-paire => ajustement
     */
    public function __construct(
        public readonly string $code = 'mbn',
        public readonly string $modeCalcul = 'formule',
        public readonly ?string $formule = 'sign(e)*min(8; abs(e)/2+1)',
        public readonly array $table = [],
        public readonly int $plafond = 8,
        public readonly int $plancher = 0,
        public readonly string $arrondi = 'inferieur',
        public readonly string $application = 'par_manche',
        public readonly bool $dynamique = false,
        public readonly int $pasDynamique = 1,
        public readonly string $methodeDouble = self::DOUBLE_MOYENNE,
        public readonly array $fonctionAjustement = [],
        public readonly string $arrondiPaire = 'differe',
        public readonly string $sensEchelle = self::SENS_RANG_HAUT_FORT,
        public readonly int $avantageResiduelFort = 0,
        public readonly int $rangNonClasse = 0,
    ) {
        if ($this->modeCalcul === 'formule' && ($this->formule === null || trim($this->formule) === '')) {
            throw new InvalidArgumentException('Un bareme en mode formule exige une formule.');
        }

        if ($this->modeCalcul === 'table' && $this->table === []) {
            throw new InvalidArgumentException('Un bareme en mode table exige une table de valeurs.');
        }
    }

    /**
     * Le bareme du Mickey By Night.
     *
     * Un point d'avance par demi-echelon d'ecart, plus un, plafonne a
     * huit. Sur les dix-huit classements AFTT, cela produit les 324
     * valeurs de la feuille HANDICAP du classeur.
     */
    public static function mbn(): self
    {
        return new self(
            code: 'mbn',
            modeCalcul: 'formule',
            formule: 'sign(e)*min(8; abs(e)/2+1)',
            plafond: 8,
            plancher: 0,
            arrondi: 'inferieur',
            application: 'par_manche',
        );
    }

    public static function depuisParametres(Parametres $p): self
    {
        return new self(
            code: $p->texte('bareme_handicap', 'personnalise'),
            modeCalcul: $p->texte('mode_calcul', 'formule'),
            formule: $p->valeur('formule') === null ? null : $p->texte('formule'),
            table: $p->table('table_valeurs'),
            plafond: $p->entier('plafond', 8) ?? 8,
            plancher: $p->entier('plancher', 0) ?? 0,
            arrondi: $p->texte('arrondi', 'inferieur'),
            application: $p->texte('application', 'par_manche'),
            dynamique: $p->booleen('dynamique'),
            pasDynamique: $p->entier('pas_dynamique', 1) ?? 1,
            methodeDouble: $p->texte('methode_double', self::DOUBLE_MOYENNE),
            fonctionAjustement: $p->table('fonction_ajustement'),
            arrondiPaire: $p->texte('arrondi_paire', 'differe'),
            sensEchelle: $p->texte('sens_echelle', self::SENS_RANG_HAUT_FORT),
            avantageResiduelFort: $p->entier('avantage_residuel_fort', 0) ?? 0,
            rangNonClasse: $p->entier('rang_non_classe', 0) ?? 0,
        );
    }

    /**
     * Handicap brut, avant plafond et arrondi.
     *
     * L'ecart est signe : positif si le camp A est le plus fort. Le
     * resultat garde ce signe, ce qui designe le beneficiaire.
     */
    public function brut(float $ecart): float
    {
        if ($this->modeCalcul === 'table') {
            $clef = (int) round(abs($ecart));

            $valeur = (float) ($this->table[$clef] ?? $this->derniereValeurTable());

            return $ecart < 0 ? -$valeur : $valeur;
        }

        return Expression::evaluer((string) $this->formule, ['e' => $ecart, 'ecart' => $ecart]);
    }

    /**
     * Handicap effectif : brut, puis avantage residuel, plafond,
     * plancher et arrondi — dans cet ordre (RG-70).
     */
    public function pourEcart(float $ecart): int
    {
        $valeur = $this->brut($ecart);
        $signe  = $valeur <=> 0.0;
        $module = abs($valeur);

        if ($this->avantageResiduelFort > 0) {
            $module = max(0.0, $module - $this->avantageResiduelFort);
        }

        $module = min((float) $this->plafond, max((float) $this->plancher, $module));

        return (int) ($signe * $this->arrondir($module));
    }

    /**
     * Apercu du bareme, pour l'etape 6 de l'ecran de definition.
     *
     * Sans ce tableau, l'etape handicap est incomprehensible sans avoir
     * lu le document : c'est le seul moyen de voir en direct ce que
     * produit une formule.
     *
     * @return array<int,int> ecart => handicap
     */
    public function apercu(int $ecartMax = 17): array
    {
        $apercu = [];

        for ($ecart = 0; $ecart <= $ecartMax; $ecart++) {
            $apercu[$ecart] = $this->pourEcart((float) $ecart);
        }

        return $apercu;
    }

    /** Le handicap depasse-t-il la moitie de la manche ? (RG-77) */
    public function inadapteA(int $pointsParManche): bool
    {
        return $this->plafond > $pointsParManche / 2;
    }

    /**
     * Le camp le plus fort est-il celui dont le rang est le plus eleve ?
     *
     * Utilise par le moteur pour orienter l'ecart (RG-71).
     */
    public function rangEleveEstFort(): bool
    {
        return $this->sensEchelle === self::SENS_RANG_HAUT_FORT;
    }

    /** Ajustement applique a une paire, selon son ecart interne. */
    public function ajustementPaire(float $ecartIntra): float
    {
        if ($this->fonctionAjustement === []) {
            return 0.0;
        }

        $clef = (int) round(abs($ecartIntra));

        if (isset($this->fonctionAjustement[$clef])) {
            return (float) $this->fonctionAjustement[$clef];
        }

        $clefs = array_keys($this->fonctionAjustement);
        sort($clefs);

        return (float) $this->fonctionAjustement[end($clefs)];
    }

    private function arrondir(float $valeur): float
    {
        return match ($this->arrondi) {
            'superieur' => ceil($valeur),
            'proche'    => round($valeur),
            'bancaire'  => round($valeur, 0, PHP_ROUND_HALF_EVEN),
            default     => floor($valeur),
        };
    }

    private function derniereValeurTable(): float
    {
        $clefs = array_keys($this->table);
        sort($clefs);

        return (float) $this->table[end($clefs)];
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'code'            => $this->code,
            'mode_calcul'     => $this->modeCalcul,
            'formule'         => $this->formule,
            'plafond'         => $this->plafond,
            'plancher'        => $this->plancher,
            'arrondi'         => $this->arrondi,
            'application'     => $this->application,
            'dynamique'       => $this->dynamique,
            'methode_double'  => $this->methodeDouble,
            'sens_echelle'    => $this->sensEchelle,
        ];
    }
}
