<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

/**
 * Les criteres de departage recenses a la section 7.4 du document.
 *
 * Chaque critere sait trois choses : dans quel sens il se lit, sur
 * quelle portee il s'applique par defaut, et s'il est calculable a
 * partir des faits enregistres. Le moteur ne connait rien d'autre —
 * c'est ce qui permet d'ajouter un critere sans toucher a
 * l'algorithme de departage.
 *
 * DEUX CRITERES MERITENT UNE NOTE
 *
 * DepartageManchesAuto est le critere qui resout le point ouvert de la
 * section 7.6 : il ne designe pas une formule mais une decision prise
 * a l'execution, en lisant le format de la phase (RG-53).
 *
 * Barrage n'est pas calculable : c'est un departage SPORTIF. Le moteur
 * s'arrete et signale a l'organisateur qu'un match doit etre joue.
 */
enum Critere: string
{
    case PointsRencontre        = 'points_rencontre';
    case Victoires              = 'victoires';
    case ConfrontationDirecte   = 'confrontation_directe';
    case QuotientVictoires      = 'quotient_victoires';
    case QuotientManches        = 'quotient_manches';
    case DiffManches            = 'diff_manches';
    case ManchesGagnees         = 'manches_gagnees';
    case RatioManches           = 'ratio_manches';
    case DiffManchesNormalisee  = 'diff_manches_normalisee';
    case QuotientPoints         = 'quotient_points';
    case DiffPoints             = 'diff_points';
    case RatioPoints            = 'ratio_points';
    case DepartageManchesAuto   = 'departage_manches_auto';
    case NbParties              = 'nb_parties';
    case PlacePoule             = 'place_poule';
    case PointsBareme           = 'points_bareme';
    case Buchholz               = 'buchholz';
    case ClassementOfficiel     = 'classement_officiel';
    case Age                    = 'age';
    case Alphabetique           = 'alphabetique';
    case TirageAuSort           = 'tirage_au_sort';
    case Barrage                = 'barrage';

    public function libelle(): string
    {
        return match ($this) {
            self::PointsRencontre       => 'Points de rencontre',
            self::Victoires             => 'Nombre de victoires',
            self::ConfrontationDirecte  => 'Confrontation directe',
            self::QuotientVictoires     => 'Quotient victoires / défaites',
            self::QuotientManches       => 'Quotient de manches',
            self::DiffManches           => 'Différence de manches',
            self::ManchesGagnees        => 'Manches gagnées',
            self::RatioManches          => 'Ratio manches gagnées / jouées',
            self::DiffManchesNormalisee => 'Différence de manches par partie',
            self::QuotientPoints        => 'Quotient de points',
            self::DiffPoints            => 'Différence de points',
            self::RatioPoints           => 'Ratio points gagnés / joués',
            self::DepartageManchesAuto  => 'Départage sur les manches (selon le format)',
            self::NbParties             => 'Nombre de parties jouées',
            self::PlacePoule            => 'Place obtenue en poule',
            self::PointsBareme          => 'Points au barème',
            self::Buchholz              => 'Buchholz (force des adversaires)',
            self::ClassementOfficiel    => 'Classement officiel',
            self::Age                   => 'Avantage au plus jeune',
            self::Alphabetique          => 'Ordre alphabétique',
            self::TirageAuSort          => 'Tirage au sort',
            self::Barrage               => 'Match de barrage',
        };
    }

    /**
     * Le critere se lit-il en croissant ?
     *
     * Croissant : la plus petite valeur est la meilleure — place en
     * poule, ordre alphabetique, age. Tous les autres se lisent en
     * decroissant.
     */
    public function croissant(): bool
    {
        return in_array($this, [self::PlacePoule, self::Alphabetique, self::Age], true);
    }

    /**
     * Portee naturelle du critere.
     *
     * La confrontation directe n'a de sens qu'entre ex aequo ; la place
     * en poule et les criteres administratifs se lisent globalement.
     */
    public function porteeParDefaut(): Portee
    {
        return match ($this) {
            self::ConfrontationDirecte, self::QuotientVictoires,
            self::QuotientManches, self::QuotientPoints => Portee::EntreExAequo,
            default                                     => Portee::ToutePoule,
        };
    }

    /**
     * Le critere se calcule-t-il a partir des faits enregistres ?
     *
     * Le barrage ne l'est pas : il faut jouer. Le moteur s'arrete et le
     * signale plutot que d'inventer un ordre.
     */
    public function calculable(): bool
    {
        return $this !== self::Barrage;
    }

    /**
     * Le critere departage-t-il a coup sur ?
     *
     * Un critere total ne laisse jamais deux entites a egalite. La
     * cascade doit se terminer par l'un d'eux si les ex aequo sont
     * interdits (RG-51).
     */
    public function total(): bool
    {
        return in_array($this, [self::Alphabetique, self::TirageAuSort], true);
    }

    /** Le critere repose-t-il sur les manches ou les points ? (RG-54) */
    public function fondeSurLesManches(): bool
    {
        return in_array($this, [
            self::QuotientManches, self::DiffManches, self::ManchesGagnees,
            self::RatioManches, self::DiffManchesNormalisee, self::DepartageManchesAuto,
            self::QuotientPoints, self::DiffPoints, self::RatioPoints,
        ], true);
    }

    /** @return list<self> */
    public static function calculables(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $c): bool => $c->calculable()
        ));
    }
}
