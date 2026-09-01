<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Handicap;

/**
 * Calcul du handicap d'une partie.
 *
 * RG-70 — handicap = bareme(force(camp A) − force(camp B)), puis
 * plafonnement, puis arrondi. Toute la difficulte tient dans la
 * fonction force, qui depend du type d'entite et, en double, de la
 * methode choisie.
 *
 * LES QUATRE METHODES DE DOUBLE (section 6.4)
 *
 *   moyenne          force = moyenne des deux classements. Simple,
 *                    mais une paire A + NC pese autant qu'une paire de
 *                    deux joueurs moyens, ce qui est faux au filet.
 *   fort_ajuste      force = classement du meilleur, corrige selon
 *                    l'ecart interne a la paire. Plus juste, et c'est
 *                    la methode ou le sens de l'echelle piege (RG-71).
 *   somme            force = somme des classements. Utilisee quand le
 *                    referentiel est cardinal (points, Elo).
 *   classement_dedie force = classement de double saisi a l'inscription.
 *
 * RG-72 — en methode fort_ajuste, les forces gardent leurs decimales et
 * l'arrondi n'intervient qu'apres la soustraction.
 *
 * RG-75 — les rangs recus ici sont les rangs GELES a l'inscription,
 * jamais les classements courants du joueur.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class MoteurHandicap
{
    /**
     * Force d'un camp, a partir des rangs geles de ses joueurs.
     *
     * @param list<int|float> $rangs
     */
    public static function force(array $rangs, BaremeHandicap $bareme, ?float $rangDedie = null): float
    {
        if ($rangs === []) {
            return (float) $bareme->rangNonClasse;
        }

        if (count($rangs) === 1) {
            return (float) $rangs[0];
        }

        return match ($bareme->methodeDouble) {
            BaremeHandicap::DOUBLE_SOMME  => array_sum($rangs),
            BaremeHandicap::DOUBLE_DEDIE  => $rangDedie ?? array_sum($rangs) / count($rangs),
            BaremeHandicap::DOUBLE_FORT_AJUSTE => self::forceFortAjuste($rangs, $bareme),
            default                       => array_sum($rangs) / count($rangs),
        };
    }

    /**
     * Force d'une paire par la methode « meilleur ajuste ».
     *
     * Le sens de l'echelle decide du SIGNE de l'ajustement : si un rang
     * eleve designe un joueur fort, une paire desequilibree est plus
     * faible que son meilleur element, donc on RETRANCHE. Si un rang
     * eleve designe un joueur faible, on AJOUTE. C'est le piege de la
     * section 12.2, et la raison d'etre de RG-71.
     *
     * @param list<int|float> $rangs
     */
    private static function forceFortAjuste(array $rangs, BaremeHandicap $bareme): float
    {
        $valeurs = array_map('floatval', $rangs);

        $meilleur = $bareme->rangEleveEstFort() ? max($valeurs) : min($valeurs);
        $moindre  = $bareme->rangEleveEstFort() ? min($valeurs) : max($valeurs);

        $ajustement = $bareme->ajustementPaire($meilleur - $moindre);

        $force = $bareme->rangEleveEstFort()
            ? $meilleur - $ajustement
            : $meilleur + $ajustement;

        return $bareme->arrondiPaire === 'immediat' ? round($force) : $force;
    }

    /**
     * Handicap d'une partie entre deux camps.
     *
     * @param list<int|float> $rangsA
     * @param list<int|float> $rangsB
     */
    public static function pourPartie(
        array $rangsA,
        array $rangsB,
        BaremeHandicap $bareme,
        ?float $dedieA = null,
        ?float $dedieB = null,
    ): ResultatHandicap {
        $forceA = self::force($rangsA, $bareme, $dedieA);
        $forceB = self::force($rangsB, $bareme, $dedieB);

        // L'ecart est oriente de facon qu'un ecart positif signifie
        // toujours « le camp A est le plus fort », quel que soit le sens
        // du referentiel (RG-71).
        $ecart = $bareme->rangEleveEstFort()
            ? $forceA - $forceB
            : $forceB - $forceA;

        $handicap = $bareme->pourEcart($ecart);

        return new ResultatHandicap(
            valeur: abs($handicap),
            beneficiaire: match (true) {
                $handicap > 0 => 'b',   // A est plus fort : B recoit l'avance
                $handicap < 0 => 'a',
                default       => null,
            },
            ecart: $ecart,
            forceA: $forceA,
            forceB: $forceB,
            application: $bareme->application,
        );
    }

    /**
     * Handicap manche par manche (RG-73, RG-74).
     *
     * En statique, toutes les manches portent la meme valeur : le cout
     * de stockage est negligeable et le handicap dynamique devient
     * ensuite une simple option, sans refonte.
     *
     * En dynamique, la manche k+1 vaut celle de la manche k, augmentee
     * du pas si le beneficiaire a perdu la manche, diminuee s'il l'a
     * gagnee — borne par le plafond et le plancher.
     *
     * @param  list<string> $vainqueursDeManche 'a' ou 'b', dans l'ordre des manches
     * @return list<array{valeur:int, beneficiaire:?string}>
     */
    public static function parManche(
        ResultatHandicap $depart,
        int $nbManches,
        BaremeHandicap $bareme,
        array $vainqueursDeManche = [],
    ): array {
        $valeurs      = [];
        $valeur       = $depart->valeur;
        $beneficiaire = $depart->beneficiaire;

        for ($manche = 0; $manche < $nbManches; $manche++) {
            $effectif = $bareme->application === 'une_fois' && $manche > 0 ? 0 : $valeur;

            $valeurs[] = [
                'valeur'       => $effectif,
                'beneficiaire' => $effectif === 0 ? null : $beneficiaire,
            ];

            if (!$bareme->dynamique || !isset($vainqueursDeManche[$manche])) {
                continue;
            }

            $valeur = $vainqueursDeManche[$manche] === $beneficiaire
                ? $valeur - $bareme->pasDynamique
                : $valeur + $bareme->pasDynamique;

            $valeur = min($bareme->plafond, max($bareme->plancher, $valeur));
        }

        return $valeurs;
    }

    /**
     * Convertit un rang dames en son equivalent messieurs (section 6.5).
     *
     * Trois politiques, conformes a l'annexe C.9 : table d'equivalence,
     * bonus fixe, ou aucune conversion. Un bareme mixte dedie se traite
     * en fournissant simplement un autre bareme.
     *
     * @param array<int,int> $equivalence rang source => rang cible
     */
    public static function convertirMixte(
        int $rang,
        string $politique,
        array $equivalence = [],
        int $bonus = 0,
    ): int {
        return match ($politique) {
            'table_equivalence' => $equivalence[$rang] ?? $rang,
            'bonus_fixe'        => $rang + $bonus,
            default             => $rang,
        };
    }
}
