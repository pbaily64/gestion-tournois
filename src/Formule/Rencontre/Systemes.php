<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Rencontre;

use InvalidArgumentException;

/**
 * Catalogue des systemes de rencontre, transcrit de l'annexe A.
 *
 * Ces sequences ne sont PAS recalculees : elles sont conventionnelles,
 * publiees par les federations, et les joueurs les reconnaissent. Un
 * litige se tranche en se referant au document officiel, pas au code.
 * C'est la meme doctrine que celle deja retenue pour l'ordre des
 * matchs de poule (Domain\OrdreMatchs).
 *
 * Le catalogue vit ici pour amorcer la base : en production, ces lignes
 * sont chargees dans systeme_rencontre / systeme_rencontre_partie et
 * l'organisateur peut en ajouter (RG-10).
 */
final class Systemes
{
    /** Swaythling : cinq simples, trois joueurs par equipe. */
    public static function swaythling(): SystemeRencontre
    {
        return self::simples(
            'swaythling',
            'Swaythling (S1)',
            [['A', 'X'], ['B', 'Y'], ['C', 'Z'], ['A', 'Y'], ['B', 'X']],
            3,
            3
        );
    }

    /** Corbillon : quatre simples et un double, le double en troisieme. */
    public static function corbillon(): SystemeRencontre
    {
        return new SystemeRencontre(
            code: 'corbillon',
            libelle: 'Corbillon (S2)',
            parties: [
                self::partie(1, 'A', 'X'),
                self::partie(2, 'B', 'Y'),
                self::partie(3, 'A+B', 'X+Y', 'double'),
                self::partie(4, 'A', 'Y'),
                self::partie(5, 'B', 'X'),
            ],
            nbJoueursMin: 2,
            nbJoueursMax: 4,
        );
    }

    /** Systeme 3 : quatre simples et un double, deux parties par joueur au plus. */
    public static function systeme3(): SystemeRencontre
    {
        return new SystemeRencontre(
            code: 's3',
            libelle: 'Système 3',
            parties: [
                self::partie(1, 'A', 'X'),
                self::partie(2, 'B', 'Y'),
                self::partie(3, 'C+A', 'Z+X', 'double'),
                self::partie(4, 'A', 'Z'),
                self::partie(5, 'C', 'Y'),
            ],
            nbJoueursMin: 3,
            nbJoueursMax: 3,
        );
    }

    /** Systeme 4 : six simples et un double, au meilleur des sept. */
    public static function systeme4(): SystemeRencontre
    {
        return new SystemeRencontre(
            code: 's4',
            libelle: 'Système 4',
            parties: [
                self::partie(1, 'A', 'Y'),
                self::partie(2, 'B', 'X'),
                self::partie(3, 'C', 'Z'),
                self::partie(4, 'A+B', 'X+Y', 'double'),
                self::partie(5, 'A', 'X'),
                self::partie(6, 'C', 'Y'),
                self::partie(7, 'B', 'Z'),
            ],
            nbJoueursMin: 3,
            nbJoueursMax: 5,
        );
    }

    /** Systeme 5 : neuf simples, chacun rencontre chacun. */
    public static function systeme5(): SystemeRencontre
    {
        return self::simples(
            's5',
            'Système 5',
            [
                ['A', 'X'], ['B', 'Y'], ['C', 'Z'],
                ['B', 'X'], ['A', 'Z'], ['C', 'Y'],
                ['B', 'Z'], ['C', 'X'], ['A', 'Y'],
            ],
            3,
            3
        );
    }

    /** Format olympique 2024 : le double ouvre la rencontre. */
    public static function olympique2024(): SystemeRencontre
    {
        return new SystemeRencontre(
            code: 'olympique_2024',
            libelle: 'Olympique 2024',
            parties: [
                self::partie(1, 'B+C', 'Y+Z', 'double'),
                self::partie(2, 'A', 'X'),
                self::partie(3, 'C', 'Z'),
                self::partie(4, 'A', 'Y'),
                self::partie(5, 'B', 'X'),
            ],
            nbJoueursMin: 3,
            nbJoueursMax: 3,
        );
    }

    /**
     * Interclubs FRBTT messieurs : seize simples, quatre joueurs.
     *
     * Toutes les parties se jouent, quelle que soit l'issue : le
     * classement des divisions repose sur le decompte total des
     * victoires individuelles.
     */
    public static function frbttMessieurs(): SystemeRencontre
    {
        return self::simples(
            'frbtt_messieurs_16',
            'Interclubs FRBTT messieurs (16 simples)',
            [
                ['4', '2'], ['4', '1'], ['4', '3'], ['4', '4'],
                ['3', '1'], ['3', '2'], ['3', '4'], ['3', '3'],
                ['2', '4'], ['2', '3'], ['2', '1'], ['2', '2'],
                ['1', '3'], ['1', '4'], ['1', '2'], ['1', '1'],
            ],
            4,
            4,
            SystemeRencontre::ARRET_TOUTES
        );
    }

    /** Interclubs FRBTT dames et jeunes : neuf simples et un double. */
    public static function frbttDames(): SystemeRencontre
    {
        return new SystemeRencontre(
            code: 'frbtt_dames_9_1',
            libelle: 'Interclubs FRBTT dames et jeunes (9 simples + 1 double)',
            parties: [
                self::partie(1, '3', '2'),
                self::partie(2, '2', '1'),
                self::partie(3, '1', '3'),
                self::partie(4, '3', '1'),
                self::partie(5, '2', '3'),
                self::partie(6, '1', '2'),
                self::partie(7, '1+2', '1+2', 'double'),
                self::partie(8, '3', '3'),
                self::partie(9, '2', '2'),
                self::partie(10, '1', '1'),
            ],
            regleArret: SystemeRencontre::ARRET_TOUTES,
            nbJoueursMin: 3,
            nbJoueursMax: 3,
        );
    }

    /** Superdivision FRBTT messieurs : six simples, pause apres C-Z. */
    public static function superdivision(): SystemeRencontre
    {
        return self::simples(
            'superdivision_6',
            'Superdivision FRBTT messieurs (6 simples)',
            [['A', 'Y'], ['B', 'X'], ['C', 'Z'], ['A', 'X'], ['C', 'Y'], ['B', 'Z']],
            3,
            4,
            SystemeRencontre::ARRET_TOUTES
        );
    }

    /**
     * Duo faible / fort avec double decisif — la formule du club.
     *
     * Les deux faibles s'affrontent, puis les deux forts, et le double
     * ne se joue qu'a une victoire partout. Le handicap du double se
     * calcule INDEPENDAMMENT de celui des simples et peut profiter au
     * camp oppose (RG-76) : ce n'est pas une incoherence, c'est la
     * consequence de deux ecarts de force differents.
     */
    public static function duoFaibleFort(): SystemeRencontre
    {
        return new SystemeRencontre(
            code: 'duo_faible_fort',
            libelle: 'Duo faible / fort avec double décisif',
            parties: [
                self::partie(1, 'faible', 'faible'),
                self::partie(2, 'fort', 'fort'),
                [
                    'ordre' => 3, 'a' => 'faible+fort', 'b' => 'faible+fort',
                    'type' => 'double', 'conditionnelle' => true,
                    'condition' => 'victoires_a = 1 et victoires_b = 1',
                    'libelle' => 'Double décisif',
                ],
            ],
            regleArret: SystemeRencontre::ARRET_A_L_ACQUIS,
            nbJoueursMin: 2,
            nbJoueursMax: 2,
        );
    }

    /** Duo fort d'abord : meme systeme, ordre inverse. */
    public static function duoFortFaible(): SystemeRencontre
    {
        return new SystemeRencontre(
            code: 'duo_fort_faible',
            libelle: 'Duo fort / faible avec double décisif',
            parties: [
                self::partie(1, 'fort', 'fort'),
                self::partie(2, 'faible', 'faible'),
                [
                    'ordre' => 3, 'a' => 'faible+fort', 'b' => 'faible+fort',
                    'type' => 'double', 'conditionnelle' => true,
                    'condition' => 'victoires_a = 1 et victoires_b = 1',
                    'libelle' => 'Double décisif',
                ],
            ],
            nbJoueursMin: 2,
            nbJoueursMax: 2,
        );
    }

    /** @return array<string,SystemeRencontre> */
    public static function catalogue(): array
    {
        $systemes = [
            self::swaythling(),
            self::corbillon(),
            self::systeme3(),
            self::systeme4(),
            self::systeme5(),
            self::olympique2024(),
            self::frbttMessieurs(),
            self::frbttDames(),
            self::superdivision(),
            self::duoFaibleFort(),
            self::duoFortFaible(),
        ];

        $indexe = [];

        foreach ($systemes as $systeme) {
            $indexe[$systeme->code] = $systeme;
        }

        return $indexe;
    }

    public static function parCode(string $code): SystemeRencontre
    {
        $catalogue = self::catalogue();

        if (!isset($catalogue[$code])) {
            throw new InvalidArgumentException(sprintf('Systeme de rencontre inconnu : %s.', $code));
        }

        return $catalogue[$code];
    }

    /**
     * @param list<array{0:string,1:string}> $couples
     */
    private static function simples(
        string $code,
        string $libelle,
        array $couples,
        int $min,
        int $max,
        string $arret = SystemeRencontre::ARRET_A_L_ACQUIS,
    ): SystemeRencontre {
        $parties = [];

        foreach ($couples as $i => [$a, $b]) {
            $parties[] = self::partie($i + 1, $a, $b);
        }

        return new SystemeRencontre($code, $libelle, $parties, $arret, $min, $max);
    }

    /**
     * @return array{ordre:int,a:string,b:string,type:string,conditionnelle:bool,condition:?string}
     */
    private static function partie(int $ordre, string $a, string $b, string $type = 'simple'): array
    {
        return [
            'ordre'          => $ordre,
            'a'              => $a,
            'b'              => $b,
            'type'           => $type,
            'conditionnelle' => false,
            'condition'      => null,
        ];
    }
}
