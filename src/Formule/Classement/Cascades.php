<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

use InvalidArgumentException;

/**
 * Les cascades de reference, transcrites de l'annexe B du document.
 *
 * Elles ne sont pas privilegiees par le moteur : ce sont des donnees
 * comme les autres, fournies ici pour eviter que chaque organisateur ne
 * les ressaisisse, et pour servir de jeu de tests contre les exemples
 * publies par les federations.
 *
 * Une cascade creee par un organisateur a exactement le meme statut.
 */
final class Cascades
{
    /**
     * ITTF, reglement 3.7.5.
     *
     * Points de rencontre d'abord, puis les memes points restreints aux
     * ex aequo, puis les trois ratios successifs. La doctrine ITTF
     * parle de RATIOS et non de differences, precisement parce qu'un
     * ratio se compare entre echantillons de tailles inegales.
     */
    public static function ittf(): Cascade
    {
        return Cascade::depuisCodes(
            [
                'points_rencontre',
                'points_rencontre@entre_ex_aequo',
                'quotient_victoires@entre_ex_aequo',
                'quotient_manches@entre_ex_aequo',
                'quotient_points@entre_ex_aequo',
            ],
            retraitIteratif: true,
            interdireExAequo: false,
            libelle: 'ITTF 3.7.5'
        );
    }

    /**
     * FFTT.
     *
     * Le nombre de victoires vient en tete, la confrontation directe
     * ne joue qu'a deux ex aequo, et l'avantage au plus jeune sert de
     * dernier recours.
     */
    public static function fftt(): Cascade
    {
        return Cascade::depuisCodes(
            [
                'victoires',
                'confrontation_directe@entre_ex_aequo',
                'quotient_victoires@entre_ex_aequo',
                'quotient_manches@entre_ex_aequo',
                'quotient_points@entre_ex_aequo',
                'age',
            ],
            retraitIteratif: true,
            interdireExAequo: false,
            libelle: 'FFTT'
        );
    }

    /**
     * FRBTT / AFTT, competition par equipes, C.7.2.
     *
     * Bareme 3-2-1-0, retrait iteratif explicite, et un match de
     * barrage en terrain neutre comme ultime recours — un departage
     * sportif, que le moteur signale sans le simuler.
     */
    public static function frbtt(): Cascade
    {
        return Cascade::depuisCodes(
            [
                'points_rencontre',
                'points_rencontre@entre_ex_aequo',
                'quotient_victoires@entre_ex_aequo',
                'quotient_manches@entre_ex_aequo',
                'quotient_points@entre_ex_aequo',
                'barrage',
            ],
            retraitIteratif: true,
            interdireExAequo: false,
            libelle: 'FRBTT / AFTT C.7.2'
        );
    }

    /**
     * Mickey By Night — classement DE POULE.
     *
     * Les joueurs d'une meme poule se sont tous rencontres : la
     * confrontation directe y a toute sa place, et le departage sur les
     * manches s'adapte au format (RG-53).
     */
    public static function mbnPoule(): Cascade
    {
        return Cascade::depuisCodes(
            [
                'points_rencontre',
                'confrontation_directe@entre_ex_aequo',
                'departage_manches_auto',
                'diff_manches',
                'classement_officiel',
                'alphabetique',
            ],
            retraitIteratif: true,
            interdireExAequo: true,
            libelle: 'Mickey By Night — poule'
        );
    }

    /**
     * Mickey By Night — classement INTER-POULES / general.
     *
     * Deux joueurs de poules differentes n'ont aucun match commun :
     * tout critere de confrontation directe est inapplicable. La place
     * obtenue en poule ouvre la cascade — tous les premiers devant tous
     * les deuxiemes — puis les victoires, puis le departage sur les
     * manches resolu a l'execution.
     *
     * Le critere « victoires » est retire automatiquement si le format
     * est en manches seches en nombre PAIR (RG-52) : c'est la cascade
     * elle-meme qui s'adapte, l'organisateur n'a rien a decider.
     */
    public static function mbnGeneral(): Cascade
    {
        return Cascade::depuisCodes(
            [
                'place_poule',
                'victoires',
                'departage_manches_auto',
                'ratio_points',
                'classement_officiel',
                'alphabetique',
            ],
            retraitIteratif: false,
            interdireExAequo: true,
            libelle: 'Mickey By Night — général'
        );
    }

    /**
     * Systeme suisse.
     *
     * Le Buchholz mesure la force des adversaires rencontres : dans un
     * suisse, deux joueurs a egalite de points n'ont pas affronte les
     * memes gens, et c'est la seule facon honnete de les separer.
     */
    public static function suisse(): Cascade
    {
        return Cascade::depuisCodes(
            [
                'points_rencontre',
                'buchholz',
                'confrontation_directe@entre_ex_aequo',
                'diff_manches',
                'classement_officiel',
                'alphabetique',
            ],
            retraitIteratif: false,
            interdireExAequo: true,
            libelle: 'Système suisse'
        );
    }

    /**
     * Classement cumule d'un criterium ou d'une saison de MbN.
     *
     * Il ne cumule PAS les manches entre phases de formats differents
     * (section 7.6) : il s'appuie sur le bareme de points, qui est
     * concu pour cela.
     */
    public static function cumulSaison(): Cascade
    {
        return Cascade::depuisCodes(
            [
                'points_bareme',
                'victoires',
                'classement_officiel',
                'alphabetique',
            ],
            retraitIteratif: false,
            interdireExAequo: true,
            libelle: 'Cumul de saison'
        );
    }

    /** @return array<string,callable():Cascade> */
    public static function catalogue(): array
    {
        return [
            'ittf'         => self::ittf(...),
            'fftt'         => self::fftt(...),
            'frbtt'        => self::frbtt(...),
            'mbn_poule'    => self::mbnPoule(...),
            'mbn_general'  => self::mbnGeneral(...),
            'suisse'       => self::suisse(...),
            'cumul_saison' => self::cumulSaison(...),
        ];
    }

    public static function parCode(string $code): Cascade
    {
        $catalogue = self::catalogue();

        if (!isset($catalogue[$code])) {
            throw new InvalidArgumentException(sprintf('Cascade de reference inconnue : %s.', $code));
        }

        return ($catalogue[$code])();
    }
}
