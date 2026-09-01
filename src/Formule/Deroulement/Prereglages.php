<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Deroulement;

use InvalidArgumentException;
use RMCF\Tournois\Formule\Flux\Flux;
use RMCF\Tournois\Formule\Flux\Selecteur;

/**
 * Les neuf prereglages du §11 — 90 % des cas en neuf lignes.
 *
 * Le document est explicite sur la facon de les traiter : un prereglage
 * n'est PAS du code, c'est un tournoi a l'etat `modele`, clonable. Creer
 * un nouveau prereglage doit etre une operation d'organisateur, pas de
 * developpeur.
 *
 * Cette classe respecte cette regle en n'etant qu'une USINE DE DONNEES :
 * chaque methode assemble une `DefinitionTournoi` et rien d'autre. Le
 * jour ou ces definitions viendront de la base plutot que d'ici, aucune
 * autre ligne du moteur ne bougera — c'est tout l'interet.
 *
 * Elles servent aussi de documentation executable : lire `mbnClassique()`
 * apprend plus vite comment se configure un MbN que n'importe quelle
 * page de manuel.
 */
final class Prereglages
{
    /** @return array<string,string> code => libelle */
    public static function catalogue(): array
    {
        return [
            'mbn_classique'      => 'MbN classique — poules, barrage, tableau et consolante',
            'mbn_sans_consolante' => 'MbN sans consolante — tableau final élargi',
            'tournoi_serie'      => 'Tournoi de série — poules de 4 puis tableau',
            'soiree_express'     => 'Soirée express — poule unique',
            'handicap_ouvert'    => 'Handicap ouvert — manche unique à 31 points',
            'double_club'        => 'Double du club — poules puis tableau',
            'duos_faible_fort'   => 'Duos faible-fort avec double décisif',
            'toutes_vies'        => 'Toutes vies — double élimination',
            'criterium_club'     => 'Critérium de club — poules à montées et descentes',
        ];
    }

    public static function parCode(string $code): DefinitionTournoi
    {
        return match ($code) {
            'mbn_classique'       => self::mbnClassique(),
            'mbn_sans_consolante' => self::mbnSansConsolante(),
            'tournoi_serie'       => self::tournoiSerie(),
            'soiree_express'      => self::soireeExpress(),
            'handicap_ouvert'     => self::handicapOuvert(),
            'double_club'         => self::doubleClub(),
            'duos_faible_fort'    => self::duosFaibleFort(),
            'toutes_vies'         => self::toutesVies(),
            'criterium_club'      => self::criteriumClub(),
            default               => throw new InvalidArgumentException("Prereglage inconnu : {$code}"),
        };
    }

    // -----------------------------------------------------------------
    // 1 — MbN classique
    // -----------------------------------------------------------------

    /**
     * Poules 3-8 → barrage → tableau final + consolante.
     *
     * Le barrage n'est pas une phase declaree : il est INTERCALE
     * automatiquement quand le flux vers le tableau depasse la capacite
     * (RG-33). C'est exactement la mecanique decrite au §5.5 et deja
     * validee sur le MbN — les 16 places du tableau, le surplus qui se
     * departage.
     */
    public static function mbnClassique(): DefinitionTournoi
    {
        return new DefinitionTournoi(
            code: 'mbn_classique',
            libelle: 'MbN classique',
            parametres: [
                'libelle'                => 'Mickey By Night',
                'type_entite'            => 'simple',
                'gel_classement'         => true,
                'moment_gel'             => 'inscription',
                'handicap_actif'         => true,
                'formule'                => 'sign(e)*min(8; abs(e)/2+1)',
                'plafond'                => 8,
                // Echelle AFTT : NC = 0, A = 17, donc rang haut = fort.
                // L'expliciter neutralise RG-71, dont l'avertissement
                // vise les referentiels ambigus, pas celui-ci.
                'sens_echelle'           => 'rang_haut_fort',
                'application'            => 'par_manche',
                'type_format'            => 'manches_seches',
                'nb_manches'             => 3,
                'points_par_manche'      => 11,
                'interdire_ex_aequo'     => true,
                'tracer_critere_decisif' => true,
                'duree_estimee_partie'   => 12,
            ],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, [
                    'taille_groupe'            => 'equilibree',
                    'methode_placement'        => 'serpentin',
                    'derniere_partie_decisive' => true,
                    'nb_qualifies'             => 2,
                ]),
                new DefinitionPhase('tableau', 'tableau', 2, [
                    'taille_tableau'      => 16,
                    'defaites_tolerees'   => 1,
                    'placement_qualifies' => 'croise',
                    'separer_meme_poule'  => 'moitie',
                ], 'Tableau final'),
                new DefinitionPhase(
                    'consolante',
                    'consolante',
                    3,
                    ['taille_tableau' => 'auto', 'defaites_tolerees' => 1],
                    'Consolante',
                    false,
                    'nb_inscrits > 16'
                ),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous, null, 1),
                new Flux(
                    'poules',
                    'tableau',
                    Selecteur::PlacesDeA,
                    '1-2',
                    2,
                    modePlacement: 'croise',
                    capaciteMax: 16,
                    siSurnombre: Flux::SURNOMBRE_BARRAGE,
                ),
                new Flux('poules', 'consolante', Selecteur::NonQualifies, null, 3),
            ],
        );
    }

    // -----------------------------------------------------------------
    // 2 — MbN sans consolante
    // -----------------------------------------------------------------

    /** Tableau final elargi, le surplus absorbe par un barrage. */
    public static function mbnSansConsolante(): DefinitionTournoi
    {
        $base = self::mbnClassique();

        return new DefinitionTournoi(
            code: 'mbn_sans_consolante',
            libelle: 'MbN sans consolante',
            parametres: $base->parametres,
            phases: [
                $base->phase('poules'),
                (new DefinitionPhase('tableau', 'tableau', 2, [
                    'taille_tableau'      => 32,
                    'defaites_tolerees'   => 1,
                    'placement_qualifies' => 'croise',
                    'separer_meme_poule'  => 'moitie',
                ], 'Tableau final élargi')),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous, null, 1),
                new Flux(
                    'poules',
                    'tableau',
                    Selecteur::Tous,
                    null,
                    2,
                    modePlacement: 'croise',
                    capaciteMax: 32,
                    siSurnombre: Flux::SURNOMBRE_BARRAGE,
                ),
            ],
        );
    }

    // -----------------------------------------------------------------
    // 3 — Tournoi de serie
    // -----------------------------------------------------------------

    public static function tournoiSerie(): DefinitionTournoi
    {
        return new DefinitionTournoi(
            code: 'tournoi_serie',
            libelle: 'Tournoi de série',
            parametres: [
                'libelle'            => 'Tournoi de série',
                'type_entite'        => 'simple',
                'type_format'        => 'manches_gagnantes',
                'nb_manches'         => 3,
                'handicap_actif'     => false,
                'interdire_ex_aequo' => true,
            ],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, [
                    'taille_groupe'            => 4,
                    'nb_qualifies'             => 2,
                    'derniere_partie_decisive' => true,
                ]),
                new DefinitionPhase('tableau', 'tableau', 2, [
                    'taille_tableau'     => 'auto',
                    'separer_meme_poule' => 'moitie',
                    'petite_finale'      => true,
                ], 'Tableau final'),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous, null, 1),
                new Flux('poules', 'tableau', Selecteur::PlacesDeA, '1-2', 2),
            ],
        );
    }

    // -----------------------------------------------------------------
    // 4 — Soiree express
    // -----------------------------------------------------------------

    public static function soireeExpress(): DefinitionTournoi
    {
        return new DefinitionTournoi(
            code: 'soiree_express',
            libelle: 'Soirée express',
            parametres: [
                'libelle'              => 'Soirée express',
                'type_entite'          => 'simple',
                'type_format'          => 'manches_gagnantes',
                'nb_manches'           => 2,
                'duree_estimee_partie' => 10,
            ],
            phases: [
                new DefinitionPhase('poule', 'poules', 1, [
                    'nb_groupes'   => 1,
                    'nb_qualifies' => 1,
                ], 'Poule unique'),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poule', Selecteur::Tous, null, 1),
            ],
        );
    }

    // -----------------------------------------------------------------
    // 5 — Handicap ouvert
    // -----------------------------------------------------------------

    /**
     * Manche unique a 31 points, handicap applique une seule fois.
     *
     * Le couplage handicap / format est ici volontaire et documente
     * (§6.3, point 4) : un bareme accordant jusqu'a 18 points d'avance
     * ne peut pas fonctionner sur une manche a 11.
     */
    public static function handicapOuvert(): DefinitionTournoi
    {
        return new DefinitionTournoi(
            code: 'handicap_ouvert',
            libelle: 'Handicap ouvert',
            parametres: [
                'libelle'           => 'Tournoi à handicap',
                'type_entite'       => 'simple',
                'type_format'       => 'manche_unique',
                'points_par_manche' => 31,
                'handicap_actif'    => true,
                'mode_calcul'       => 'formule',
                'formule'           => 'sign(e)*min(18; abs(e)*2)',
                'plafond'           => 18,
                'application'       => 'une_fois',
            ],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, [
                    'taille_groupe' => 4,
                    'nb_qualifies'  => 2,
                ]),
                new DefinitionPhase('tableau', 'tableau', 2, ['taille_tableau' => 'auto'], 'Tableau final'),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous, null, 1),
                new Flux('poules', 'tableau', Selecteur::PlacesDeA, '1-2', 2),
            ],
        );
    }

    // -----------------------------------------------------------------
    // 6 — Double du club
    // -----------------------------------------------------------------

    public static function doubleClub(): DefinitionTournoi
    {
        return new DefinitionTournoi(
            code: 'double_club',
            libelle: 'Double du club',
            parametres: [
                'libelle'            => 'Double du club',
                'type_entite'        => 'double',
                'taille_entite'      => 2,
                'composition_paire'  => 'fixe_inscription',
                'type_format'        => 'manches_gagnantes',
                'nb_manches'         => 3,
                'handicap_actif'     => true,
                'methode_double'     => 'moyenne',
                'arrondi_paire'      => 'differe',
                'application'        => 'par_manche',
            ],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, ['taille_groupe' => 4, 'nb_qualifies' => 2]),
                new DefinitionPhase('tableau', 'tableau', 2, ['taille_tableau' => 'auto'], 'Tableau final'),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous, null, 1),
                new Flux('poules', 'tableau', Selecteur::PlacesDeA, '1-2', 2),
            ],
        );
    }

    // -----------------------------------------------------------------
    // 7 — Duos faible-fort
    // -----------------------------------------------------------------

    /**
     * Le duo du §2.4 : faibles, puis forts, puis double si 1-1.
     *
     * Les deux handicaps de la rencontre sont calcules independamment
     * (RG-76) et peuvent beneficier a des camps opposes : ce n'est pas
     * une anomalie, c'est le cas normal des que les duos sont desequilibres
     * differemment (§6.7).
     */
    public static function duosFaibleFort(): DefinitionTournoi
    {
        return new DefinitionTournoi(
            code: 'duos_faible_fort',
            libelle: 'Duos faible-fort',
            parametres: [
                'libelle'                   => 'Duos faible-fort',
                'type_entite'               => 'duo',
                'taille_entite'             => 2,
                'systeme_rencontre'         => 'duo_faible_fort',
                'regle_arret_rencontre'     => 'a_l_acquis',
                'affectation_roles'         => 'par_classement_gele',
                'ordre_roles'               => 'faible_puis_fort',
                'partie_decisive'           => 'conditionnelle',
                'condition_partie_decisive' => 'victoires_a = 1 et victoires_b = 1',
                'type_format'               => 'manches_gagnantes',
                'nb_manches'                => 3,
                'handicap_actif'            => true,
                'methode_double'            => 'fort_ajuste',
                'handicap_partie_decisive'  => 'identique',
            ],
            phases: [
                new DefinitionPhase('poules', 'poules', 1, ['taille_groupe' => 4, 'nb_qualifies' => 2]),
                new DefinitionPhase('tableau', 'tableau', 2, ['taille_tableau' => 'auto'], 'Tableau final'),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'poules', Selecteur::Tous, null, 1),
                new Flux('poules', 'tableau', Selecteur::PlacesDeA, '1-2', 2),
            ],
        );
    }

    // -----------------------------------------------------------------
    // 8 — Toutes vies
    // -----------------------------------------------------------------

    public static function toutesVies(): DefinitionTournoi
    {
        return new DefinitionTournoi(
            code: 'toutes_vies',
            libelle: 'Toutes vies',
            parametres: [
                'libelle'     => 'Tournoi à deux vies',
                'type_entite' => 'simple',
                'type_format' => 'manches_gagnantes',
                'nb_manches'  => 3,
            ],
            phases: [
                new DefinitionPhase('tableau', 'tableau', 1, [
                    'taille_tableau'      => 'auto',
                    'defaites_tolerees'   => 2,
                    'destination_perdant' => 'branche_perdants',
                    'grande_finale_reset' => true,
                ], 'Double élimination'),
            ],
            flux: [
                new Flux(Flux::SOURCE_INSCRIPTIONS, 'tableau', Selecteur::Tous, null, 1),
            ],
        );
    }

    // -----------------------------------------------------------------
    // 9 — Criterium de club
    // -----------------------------------------------------------------

    /**
     * Trois tours de poules avec montees et descentes (§5.6b).
     *
     * C'est la famille structurellement la plus proche du MbN sur une
     * saison : plusieurs soirees, un classement cumule, des joueurs qui
     * changent de niveau. Les montees et les descentes ne sont pas un
     * mecanisme special — ce sont deux flux de plus.
     */
    public static function criteriumClub(int $nbTours = 3): DefinitionTournoi
    {
        $phases = [];
        $flux   = [new Flux(Flux::SOURCE_INSCRIPTIONS, 'tour1', Selecteur::Tous, null, 1)];

        for ($tour = 1; $tour <= $nbTours; $tour++) {
            $phases[] = new DefinitionPhase("tour{$tour}", 'poules', $tour, [
                'taille_groupe' => 4,
                'nb_qualifies'  => 1,
            ], "Tour {$tour}");

            if ($tour === $nbTours) {
                continue;
            }

            $suivant = $tour + 1;

            $flux[] = new Flux("tour{$tour}", "tour{$suivant}", Selecteur::Montants, 1, $tour * 10 + 1);
            $flux[] = new Flux("tour{$tour}", "tour{$suivant}", Selecteur::Descendants, 1, $tour * 10 + 2);
            $flux[] = new Flux("tour{$tour}", "tour{$suivant}", Selecteur::NonQualifies, null, $tour * 10 + 3);
        }

        return new DefinitionTournoi(
            code: 'criterium_club',
            libelle: 'Critérium de club',
            parametres: [
                'libelle'                => 'Critérium de club',
                'type_entite'            => 'simple',
                'type_format'            => 'manches_gagnantes',
                'nb_manches'             => 3,
                'cumul'                  => 'somme_toutes_journees',
                'points_participation'   => 5,
                'bonus_vainqueur_poule'  => 5,
                'agregation_multi_phases' => 'bareme_points',
            ],
            phases: $phases,
            flux: $flux,
        );
    }
}
