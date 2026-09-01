<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule;

use InvalidArgumentException;

/**
 * Catalogue des parametres de configuration d'un tournoi.
 *
 * C'est la transcription executable de l'annexe C du document
 * « Formules de tournois en tennis de table ». Chaque parametre y porte
 * son code, son bloc, son domaine de valeurs, son defaut, sa portee et
 * sa condition de visibilite.
 *
 * Ce catalogue a trois usages, et c'est ce qui justifie de le tenir a
 * un seul endroit :
 *
 *   1. le moteur y lit les valeurs par defaut (aucun defaut n'est
 *      ecrit en dur ailleurs dans le code) ;
 *   2. le validateur y lit les domaines pour refuser une valeur hors
 *      domaine avant l'ouverture du tournoi ;
 *   3. l'ecran de definition (etapes 1 a 6) s'en genere : ajouter un
 *      parametre ici le fait apparaitre dans le formulaire, sans
 *      toucher au gabarit.
 *
 * PORTEE — un parametre se regle a l'un de ces cinq niveaux, la valeur
 * du niveau le plus fin l'emportant (voir Parametres) :
 *
 *     tournoi -> phase -> groupe -> tour -> partie
 *
 * Les libelles sont accentues : ils sont destines a l'affichage, comme
 * ceux des gabarits. Les commentaires et la documentation suivent la
 * convention du dossier src/, sans accents.
 */
final class Catalogue
{
    public const BLOC_TOURNOI     = 'tournoi';
    public const BLOC_COMPOSITION = 'composition';
    public const BLOC_PHASE       = 'phase';
    public const BLOC_FLUX        = 'flux';
    public const BLOC_FORMAT      = 'format';
    public const BLOC_CLASSEMENT  = 'classement';
    public const BLOC_BAREME      = 'bareme';
    public const BLOC_HANDICAP    = 'handicap';
    public const BLOC_EXECUTION   = 'execution';
    public const BLOC_PLANIF      = 'planification';

    /** @var array<string,string> */
    public const BLOCS = [
        self::BLOC_TOURNOI     => 'Identité et cadre',
        self::BLOC_COMPOSITION => 'Composition — qui joue',
        self::BLOC_PHASE       => 'Phases — structure',
        self::BLOC_FLUX        => 'Flux — enchaînement des phases',
        self::BLOC_FORMAT      => 'Format de partie',
        self::BLOC_CLASSEMENT  => 'Classement',
        self::BLOC_BAREME      => 'Barème de points',
        self::BLOC_HANDICAP    => 'Handicap',
        self::BLOC_EXECUTION   => 'Exécution — forfaits et incidents',
        self::BLOC_PLANIF      => 'Planification',
    ];

    /**
     * Le catalogue lui-meme.
     *
     * Chaque entree :
     *   bloc       : voir BLOCS
     *   libelle    : intitule affiche
     *   domaine    : liste de valeurs admises, ou type scalaire
     *                (entier, decimal, texte, booleen, date, reference,
     *                 table, expression, liste)
     *   defaut     : valeur par defaut, null si obligatoire
     *   portee     : niveau le plus large ou le parametre se regle
     *   visible_si : condition d'affichage, exprimee sur d'autres codes
     *   rg         : regles de gestion de l'annexe C qui s'y rapportent
     *
     * @var array<string, array{
     *     bloc:string, libelle:string, domaine:string|list<string>,
     *     defaut:mixed, portee:string, visible_si?:string, rg?:list<string>
     * }>
     */
    private const PARAMETRES = [

        // --- C.2 TOURNOI --------------------------------------------
        'libelle' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Libellé du tournoi',
            'domaine' => 'texte', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'saison' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Saison',
            'domaine' => 'reference', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'date_debut' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Date de début',
            'domaine' => 'date', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'date_fin' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Date de fin',
            'domaine' => 'date', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'type_entite' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Type d\'entité en lice',
            'domaine' => ['simple', 'double', 'duo', 'equipe'],
            'defaut' => 'simple', 'portee' => 'tournoi', 'rg' => ['RG-01'],
        ],
        'referentiel_classement' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Référentiel de classement',
            'domaine' => 'reference', 'defaut' => 'aftt', 'portee' => 'tournoi',
            'rg' => ['RG-01'],
        ],
        'gel_classement' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Geler les classements',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'tournoi',
            'rg' => ['RG-02', 'RG-75'],
        ],
        'moment_gel' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Moment du gel',
            'domaine' => ['inscription', 'ouverture', 'debut_phase'],
            'defaut' => 'inscription', 'portee' => 'tournoi',
            'visible_si' => 'gel_classement', 'rg' => ['RG-02'],
        ],
        'mixite' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Mixité',
            'domaine' => ['libre', 'messieurs', 'dames', 'mixte_impose'],
            'defaut' => 'libre', 'portee' => 'tournoi',
        ],
        'categorie_age' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Catégories d\'âge admises',
            'domaine' => 'liste', 'defaut' => [], 'portee' => 'tournoi',
        ],
        'plage_classement' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Plage de classement',
            'domaine' => 'table', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'nb_inscrits_max' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Nombre d\'inscrits maximum',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'visibilite' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'Visibilité',
            'domaine' => ['prive', 'club', 'public'], 'defaut' => 'club',
            'portee' => 'tournoi',
        ],
        'etat' => [
            'bloc' => self::BLOC_TOURNOI, 'libelle' => 'État',
            'domaine' => ['modele', 'brouillon', 'ouvert', 'en_cours', 'cloture', 'archive'],
            'defaut' => 'brouillon', 'portee' => 'tournoi',
            'rg' => ['RG-01', 'RG-03'],
        ],

        // --- C.3 COMPOSITION ----------------------------------------
        'taille_entite' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Joueurs par entité',
            'domaine' => 'entier', 'defaut' => 1, 'portee' => 'tournoi',
        ],
        'composition_paire' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Composition des paires',
            'domaine' => ['fixe_inscription', 'tiree_par_tour', 'formee_par_organisateur', 'montante'],
            'defaut' => 'fixe_inscription', 'portee' => 'tournoi',
            'visible_si' => 'type_entite in double,duo', 'rg' => ['RG-14'],
        ],
        'contrainte_composition' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Contrainte de composition',
            'domaine' => ['aucune', 'somme_max', 'ecart_max', 'ecart_min', 'un_de_chaque_sexe', 'un_jeune'],
            'defaut' => 'aucune', 'portee' => 'tournoi',
            'visible_si' => 'type_entite in double,duo,equipe',
        ],
        'contrainte_valeur' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Valeur de la contrainte',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'tournoi',
            'visible_si' => 'contrainte_composition not aucune',
        ],
        'systeme_rencontre' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Système de rencontre',
            'domaine' => 'reference', 'defaut' => null, 'portee' => 'phase',
            'visible_si' => 'type_entite in duo,equipe', 'rg' => ['RG-10'],
        ],
        'regle_arret_rencontre' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Règle d\'arrêt',
            'domaine' => ['a_l_acquis', 'toutes_parties'], 'defaut' => 'a_l_acquis',
            'portee' => 'phase', 'visible_si' => 'type_entite in duo,equipe',
            'rg' => ['RG-11', 'RG-12'],
        ],
        'affectation_roles' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Affectation des rôles',
            'domaine' => ['libre', 'par_classement_gele', 'par_liste_forces', 'choix_capitaine'],
            'defaut' => 'par_classement_gele', 'portee' => 'phase',
            'visible_si' => 'type_entite in duo,equipe', 'rg' => ['RG-13'],
        ],
        'ordre_roles' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Ordre des rôles',
            'domaine' => ['faible_puis_fort', 'fort_puis_faible'],
            'defaut' => 'faible_puis_fort', 'portee' => 'phase',
            'visible_si' => 'type_entite = duo',
        ],
        'partie_decisive' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Partie décisive',
            'domaine' => ['conditionnelle', 'systematique', 'absente'],
            'defaut' => 'conditionnelle', 'portee' => 'phase',
            'visible_si' => 'type_entite = duo',
        ],
        'condition_partie_decisive' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Condition de la partie décisive',
            'domaine' => 'expression', 'defaut' => 'score = 1-1', 'portee' => 'phase',
            'visible_si' => 'partie_decisive = conditionnelle',
        ],
        'remplacement_autorise' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Remplacement autorisé',
            'domaine' => 'booleen', 'defaut' => false, 'portee' => 'phase',
            'visible_si' => 'type_entite = equipe',
        ],
        'nb_parties_max_par_joueur' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Parties maximum par joueur',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'phase',
            'visible_si' => 'type_entite = equipe',
        ],
        'mode_relais' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Mode de relais',
            'domaine' => ['aucun', 'au_score', 'a_la_manche', 'au_point', 'au_temps', 'libre'],
            'defaut' => 'aucun', 'portee' => 'phase', 'visible_si' => 'type_entite = duo',
        ],
        'seuil_relais' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Seuil de relais',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'phase',
            'visible_si' => 'mode_relais not aucun',
        ],
        'nb_changements_max' => [
            'bloc' => self::BLOC_COMPOSITION, 'libelle' => 'Changements maximum',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'phase',
            'visible_si' => 'mode_relais = libre',
        ],

        // --- C.4 PHASE ----------------------------------------------
        'type_phase' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Type de phase',
            // `croise` comble la premiere des deux lacunes assumees de la
            // matrice C.12 : l'appariement Scheveningen, ou chaque membre
            // du groupe A rencontre chaque membre du groupe B sans jamais
            // rencontrer les siens. Le document annonçait un cout faible ;
            // il l'etait, le seul travail reel etant l'ordonnancement.
            'domaine' => [
                'poules', 'tableau', 'consolante', 'barrage',
                'suisse', 'echelle', 'classement_integral', 'croise',
            ],
            'defaut' => 'poules', 'portee' => 'phase',
        ],
        'obligatoire' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Phase obligatoire',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase',
            'rg' => ['RG-21'],
        ],
        'condition_activation' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Condition d\'activation',
            'domaine' => 'expression', 'defaut' => null, 'portee' => 'phase',
            'rg' => ['RG-21'],
        ],
        'nb_groupes' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Nombre de poules',
            'domaine' => 'entier', 'defaut' => 'auto', 'portee' => 'phase',
            'visible_si' => 'type_phase = poules',
        ],
        'taille_groupe' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Taille des poules',
            'domaine' => 'entier', 'defaut' => 'equilibree', 'portee' => 'phase',
            'visible_si' => 'type_phase = poules',
        ],
        'tolerance_taille' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Écart maximum entre poules',
            'domaine' => 'entier', 'defaut' => 1, 'portee' => 'phase',
            'visible_si' => 'type_phase = poules',
        ],
        'methode_placement' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Méthode de placement',
            'domaine' => ['serpentin', 'tirage', 'manuel', 'serpentin_puis_tirage'],
            'defaut' => 'serpentin', 'portee' => 'phase',
            'visible_si' => 'type_phase = poules',
        ],
        'criteres_separation' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Critères de séparation',
            'domaine' => 'liste', 'defaut' => ['meme_club'], 'portee' => 'phase',
            'visible_si' => 'type_phase = poules',
        ],
        'ordre_parties' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Ordre des parties',
            'domaine' => ['officiel', 'libre', 'personnalise'], 'defaut' => 'officiel',
            'portee' => 'phase', 'visible_si' => 'type_phase = poules',
        ],
        'derniere_partie_decisive' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Dernière partie décisive (ITTF 3.7.5.5)',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase',
            'visible_si' => 'type_phase = poules',
        ],
        'nb_qualifies' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Qualifiés par poule',
            'domaine' => 'entier', 'defaut' => 2, 'portee' => 'phase',
            'visible_si' => 'type_phase = poules',
        ],
        'report_resultats_phase_precedente' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Reporter les résultats précédents',
            'domaine' => 'booleen', 'defaut' => false, 'portee' => 'phase',
            'visible_si' => 'type_phase = poules', 'rg' => ['RG-23'],
        ],
        'rejouer_confrontations_deja_disputees' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Rejouer les confrontations déjà disputées',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase',
            'visible_si' => 'type_phase = poules', 'rg' => ['RG-23'],
        ],
        'taille_tableau' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Taille du tableau',
            'domaine' => 'entier', 'defaut' => 'auto', 'portee' => 'phase',
            'visible_si' => 'type_phase in tableau,consolante,classement_integral',
            'rg' => ['RG-20'],
        ],
        'defaites_tolerees' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Défaites tolérées',
            'domaine' => 'entier', 'defaut' => 1, 'portee' => 'phase',
            'visible_si' => 'type_phase in tableau,consolante,classement_integral',
            'rg' => ['RG-22'],
        ],
        'destination_perdant' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Destination du perdant',
            'domaine' => ['sortie', 'tableau_secondaire', 'branche_perdants', 'tour_de_classement'],
            'defaut' => 'sortie', 'portee' => 'phase',
            'visible_si' => 'type_phase in tableau,consolante,classement_integral',
            'rg' => ['RG-22'],
        ],
        'placement_exempts' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Placement des exempts',
            'domaine' => ['mieux_classes', 'tirage', 'manuel'], 'defaut' => 'mieux_classes',
            'portee' => 'phase', 'visible_si' => 'type_phase in tableau,consolante',
            'rg' => ['RG-20'],
        ],
        'placement_qualifies' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Placement des qualifiés',
            'domaine' => ['croise', 'tirage_dirige', 'tirage_integral', 'manuel'],
            'defaut' => 'croise', 'portee' => 'phase',
            'visible_si' => 'type_phase in tableau,consolante',
        ],
        'separer_meme_poule' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Séparer les joueurs d\'une même poule',
            'domaine' => ['non', 'moitie', 'quart', 'demie'], 'defaut' => 'non',
            'portee' => 'phase', 'visible_si' => 'type_phase in tableau,consolante',
            'rg' => ['RG-34'],
        ],
        'echange_positions_manuel' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Échange manuel des positions',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase',
            'visible_si' => 'type_phase in tableau,consolante',
        ],
        'petite_finale' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Petite finale',
            'domaine' => 'booleen', 'defaut' => false, 'portee' => 'phase',
            'visible_si' => 'type_phase in tableau,consolante',
        ],
        'matchs_de_classement' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Matchs de classement',
            'domaine' => ['aucun', 'places_3_4', 'toutes_places'], 'defaut' => 'aucun',
            'portee' => 'phase', 'visible_si' => 'type_phase in tableau,consolante,classement_integral',
        ],
        'grande_finale_reset' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Remise à zéro en grande finale',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase',
            'visible_si' => 'destination_perdant = branche_perdants',
        ],
        'moment' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Moment du barrage',
            'domaine' => ['apres_poules', 'fin_de_phase', 'fin_de_tournoi'],
            'defaut' => 'apres_poules', 'portee' => 'phase',
            'visible_si' => 'type_phase = barrage',
        ],
        'objet' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Objet du barrage',
            'domaine' => ['acces_tableau', 'depart_ex_aequo', 'montee_descente', 'attribution_titre'],
            'defaut' => 'acces_tableau', 'portee' => 'phase',
            'visible_si' => 'type_phase = barrage',
        ],
        'format_barrage' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Forme du barrage',
            'domaine' => ['match_unique', 'mini_poule', 'elimination_directe'],
            'defaut' => 'match_unique', 'portee' => 'phase',
            'visible_si' => 'type_phase = barrage',
        ],
        'lieu_neutre' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Lieu neutre',
            'domaine' => 'booleen', 'defaut' => false, 'portee' => 'phase',
            'visible_si' => 'type_phase = barrage',
        ],
        'si_egalite_persistante' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Si égalité persistante',
            'domaine' => ['tirage_au_sort', 'classement_officiel', 'rejouer'],
            'defaut' => 'classement_officiel', 'portee' => 'phase',
            'visible_si' => 'type_phase = barrage',
        ],
        'nb_tours' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Nombre de tours',
            'domaine' => 'entier', 'defaut' => 'auto', 'portee' => 'phase',
            'visible_si' => 'type_phase = suisse',
        ],
        'appariement' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Méthode d\'appariement',
            'domaine' => ['neerlandais', 'monrad', 'burstein', 'aleatoire'],
            'defaut' => 'neerlandais', 'portee' => 'phase',
            'visible_si' => 'type_phase = suisse',
        ],
        'appariement_tour_1' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Appariement du premier tour',
            'domaine' => ['tetes_de_serie', 'aleatoire'], 'defaut' => 'tetes_de_serie',
            'portee' => 'phase', 'visible_si' => 'type_phase = suisse',
        ],
        'interdire_revanche' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Interdire les revanches',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase',
            'visible_si' => 'type_phase = suisse',
        ],
        'politique_exempt' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Politique d\'exemption',
            'domaine' => ['jamais_deux_fois', 'plus_faible', 'aleatoire'],
            'defaut' => 'jamais_deux_fois', 'portee' => 'phase',
            'visible_si' => 'type_phase = suisse',
        ],
        'points_exempt' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Points de l\'exempt',
            'domaine' => 'decimal', 'defaut' => 1.0, 'portee' => 'phase',
            'visible_si' => 'type_phase = suisse',
        ],
        'nb_tables_echelle' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Nombre de tables (échelle)',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'phase',
            'visible_si' => 'type_phase = echelle',
        ],
        'regle_montee' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Règle de montée',
            'domaine' => ['vainqueur_monte', 'vainqueur_monte_2'], 'defaut' => 'vainqueur_monte',
            'portee' => 'phase', 'visible_si' => 'type_phase = echelle',
        ],
        'duree_par_tour' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Durée par tour',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'phase',
            'visible_si' => 'type_phase = echelle',
        ],
        'classement_final_echelle' => [
            'bloc' => self::BLOC_PHASE, 'libelle' => 'Classement final (échelle)',
            'domaine' => ['position_table', 'points_cumules'], 'defaut' => 'position_table',
            'portee' => 'phase', 'visible_si' => 'type_phase = echelle',
        ],

        // --- C.5 FLUX -----------------------------------------------
        //
        // Un flux relie une phase source a une phase cible. Les deux
        // extremites et le selecteur sont des REFERENCES, portees par la
        // table `flux_qualification` et par la classe Flux ; ne figurent
        // ici que les reglages scalaires, ceux que l'ecran de definition
        // doit savoir presenter et valider.
        'selecteur' => [
            'bloc' => self::BLOC_FLUX, 'libelle' => 'Sélecteur',
            'domaine' => [
                'place_exacte', 'places_de_a', 'meilleurs_n_iemes', 'non_qualifies',
                'perdants_tour', 'vainqueurs_tour', 'elimines_avec_n_defaites',
                'top_n_global', 'montants', 'descendants', 'repeches', 'tous', 'manuel',
            ],
            'defaut' => 'place_exacte', 'portee' => 'phase',
            'rg' => ['RG-31', 'RG-32'],
        ],
        'parametre_selecteur' => [
            'bloc' => self::BLOC_FLUX, 'libelle' => 'Paramètre du sélecteur',
            'domaine' => 'texte', 'defaut' => null, 'portee' => 'phase',
        ],
        'tour_entree_cible' => [
            'bloc' => self::BLOC_FLUX, 'libelle' => 'Tour d\'entrée dans la phase cible',
            'domaine' => 'texte', 'defaut' => 'auto', 'portee' => 'phase',
        ],
        'mode_placement' => [
            'bloc' => self::BLOC_FLUX, 'libelle' => 'Mode de placement',
            'domaine' => ['tetes_de_serie', 'croise', 'miroir', 'tirage', 'serpentin', 'manuel'],
            'defaut' => 'croise', 'portee' => 'phase', 'rg' => ['RG-34'],
        ],
        'capacite_max' => [
            'bloc' => self::BLOC_FLUX, 'libelle' => 'Capacité maximale de la cible',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'phase', 'rg' => ['RG-33'],
        ],
        'si_surnombre' => [
            'bloc' => self::BLOC_FLUX, 'libelle' => 'En cas de surnombre',
            'domaine' => ['barrage', 'tronquer', 'elargir_cible'],
            'defaut' => 'barrage', 'portee' => 'phase', 'rg' => ['RG-33'],
        ],
        'si_sous_nombre' => [
            'bloc' => self::BLOC_FLUX, 'libelle' => 'En cas de sous-nombre',
            'domaine' => ['exempts', 'repecher', 'reduire_cible'],
            'defaut' => 'exempts', 'portee' => 'phase',
        ],

        // --- C.6 FORMAT DE PARTIE -----------------------------------
        'type_format' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Type de format',
            'domaine' => ['manches_gagnantes', 'manches_seches', 'manche_unique', 'au_temps', 'score_cible'],
            'defaut' => 'manches_gagnantes', 'portee' => 'tournoi',
            'rg' => ['RG-41', 'RG-42'],
        ],
        'nb_manches' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Nombre de manches',
            'domaine' => 'entier', 'defaut' => 3, 'portee' => 'tournoi',
            'rg' => ['RG-52'],
        ],
        'points_par_manche' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Points par manche',
            'domaine' => 'entier', 'defaut' => 11, 'portee' => 'tournoi',
            'rg' => ['RG-40', 'RG-77'],
        ],
        'deux_points_ecart' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Deux points d\'écart',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'tournoi',
        ],
        'plafond_manche' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Plafond de la manche',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'changement_service' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Changement de service tous les',
            'domaine' => 'entier', 'defaut' => 2, 'portee' => 'tournoi',
        ],
        'changement_service_apres_egalite' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Changement de service après égalité',
            'domaine' => 'entier', 'defaut' => 1, 'portee' => 'tournoi',
        ],
        'seuil_egalite' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Seuil d\'égalité',
            'domaine' => 'entier', 'defaut' => 'auto', 'portee' => 'tournoi',
            'rg' => ['RG-40'],
        ],
        'changement_cote_manche_decisive' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Changement de côté (manche décisive)',
            'domaine' => 'entier', 'defaut' => 'auto', 'portee' => 'tournoi',
            'rg' => ['RG-40'],
        ],
        'acceleration' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Système d\'accélération',
            'domaine' => ['interdite', 'autorisee', 'imposee'], 'defaut' => 'autorisee',
            'portee' => 'tournoi',
        ],
        'acceleration_apres' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Accélération après (minutes)',
            'domaine' => 'entier', 'defaut' => 10, 'portee' => 'tournoi',
            'visible_si' => 'acceleration not interdite',
        ],
        'duree' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Durée (minutes)',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'tournoi',
            'visible_si' => 'type_format = au_temps',
        ],
        'arbitrage' => [
            'bloc' => self::BLOC_FORMAT, 'libelle' => 'Arbitrage',
            'domaine' => ['joueur_suivant', 'designe', 'aucun'], 'defaut' => 'joueur_suivant',
            'portee' => 'phase',
        ],

        // --- C.7 CLASSEMENT -----------------------------------------
        'bareme_points_rencontre' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Barème de points de rencontre',
            'domaine' => ['2-1-0', '3-2-1-0', '3-1-0', '1-0', 'personnalise'],
            'defaut' => '2-1-0', 'portee' => 'phase',
        ],
        'points_forfait' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Points en cas de forfait',
            'domaine' => 'entier', 'defaut' => 0, 'portee' => 'phase', 'rg' => ['RG-80'],
        ],
        'points_defaite_jouee' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Points pour une défaite jouée',
            'domaine' => 'entier', 'defaut' => 1, 'portee' => 'phase', 'rg' => ['RG-80'],
        ],
        'criteres' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Cascade de départage',
            'domaine' => 'liste', 'defaut' => null, 'portee' => 'phase',
            'rg' => ['RG-50', 'RG-53'],
        ],
        'retrait_iteratif' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Retrait itératif',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase', 'rg' => ['RG-50'],
        ],
        'interdire_ex_aequo' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Interdire les ex æquo',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase', 'rg' => ['RG-51'],
        ],
        'critere_backstop' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Critère de dernier recours',
            'domaine' => 'liste', 'defaut' => ['classement_officiel', 'alphabetique'],
            'portee' => 'phase', 'rg' => ['RG-51'],
        ],
        'tracer_critere_decisif' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Tracer le critère décisif',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'phase', 'rg' => ['RG-56'],
        ],
        'agregation_multi_phases' => [
            'bloc' => self::BLOC_CLASSEMENT, 'libelle' => 'Agrégation entre phases',
            'domaine' => ['bareme_points', 'normalisee_par_phase', 'interdite'],
            'defaut' => 'bareme_points', 'portee' => 'tournoi', 'rg' => ['RG-54'],
        ],

        // --- C.8 BAREME DE POINTS -----------------------------------
        'points_participation' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Points de participation',
            'domaine' => 'entier', 'defaut' => 5, 'portee' => 'tournoi',
        ],
        'points_victoire_poule' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Points par victoire en poule',
            'domaine' => 'entier', 'defaut' => 1, 'portee' => 'tournoi',
        ],
        'bonus_vainqueur_poule' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Bonus vainqueur de poule',
            'domaine' => 'entier', 'defaut' => 5, 'portee' => 'tournoi',
        ],
        'bareme_consolante' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Barème consolante',
            'domaine' => 'table', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'bareme_tableau_final' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Barème tableau final',
            'domaine' => 'table', 'defaut' => null, 'portee' => 'tournoi',
        ],
        'points_forfait_declare' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Points pour forfait déclaré',
            'domaine' => 'entier', 'defaut' => 0, 'portee' => 'tournoi',
        ],
        'points_arbitrage' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Points d\'arbitrage',
            'domaine' => 'entier', 'defaut' => 0, 'portee' => 'tournoi',
        ],
        'cumul' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Mode de cumul',
            'domaine' => ['somme_toutes_journees', 'n_meilleures_journees', 'moyenne'],
            'defaut' => 'somme_toutes_journees', 'portee' => 'tournoi', 'rg' => ['RG-61'],
        ],
        'n_meilleures' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Nombre de journées retenues',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'tournoi',
            'visible_si' => 'cumul = n_meilleures_journees', 'rg' => ['RG-61'],
        ],
        'cloture_journee' => [
            'bloc' => self::BLOC_BAREME, 'libelle' => 'Clôture de journée',
            'domaine' => 'booleen', 'defaut' => true, 'portee' => 'tournoi',
        ],

        // --- C.9 HANDICAP -------------------------------------------
        'handicap_actif' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Handicap actif',
            'domaine' => 'booleen', 'defaut' => false, 'portee' => 'tournoi',
        ],
        'sens_echelle' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Sens de l\'échelle',
            'domaine' => ['rang_haut_fort', 'rang_haut_faible'], 'defaut' => 'rang_haut_fort',
            'portee' => 'tournoi', 'visible_si' => 'handicap_actif', 'rg' => ['RG-71'],
        ],
        'mode_calcul' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Mode de calcul',
            'domaine' => ['formule', 'table'], 'defaut' => 'formule', 'portee' => 'tournoi',
            'visible_si' => 'handicap_actif',
        ],
        'formule' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Formule',
            'domaine' => 'expression', 'defaut' => 'sign(e)*min(8; abs(e)/2+1)',
            'portee' => 'tournoi', 'visible_si' => 'mode_calcul = formule',
        ],
        'table_valeurs' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Table écart -> handicap',
            'domaine' => 'table', 'defaut' => null, 'portee' => 'tournoi',
            'visible_si' => 'mode_calcul = table',
        ],
        'plafond' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Plafond',
            'domaine' => 'entier', 'defaut' => 8, 'portee' => 'tournoi',
            'visible_si' => 'handicap_actif', 'rg' => ['RG-77'],
        ],
        'plancher' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Plancher',
            'domaine' => 'entier', 'defaut' => 0, 'portee' => 'tournoi',
            'visible_si' => 'handicap_actif',
        ],
        'arrondi' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Arrondi',
            'domaine' => ['inferieur', 'superieur', 'proche', 'bancaire'],
            'defaut' => 'inferieur', 'portee' => 'tournoi', 'visible_si' => 'handicap_actif',
        ],
        'avantage_residuel_fort' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Avantage résiduel du fort',
            'domaine' => 'entier', 'defaut' => 0, 'portee' => 'tournoi',
            'visible_si' => 'handicap_actif',
        ],
        'application' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Application',
            'domaine' => ['par_manche', 'une_fois', 'en_manches'], 'defaut' => 'par_manche',
            'portee' => 'phase', 'visible_si' => 'handicap_actif', 'rg' => ['RG-73'],
        ],
        'dynamique' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Handicap dynamique',
            'domaine' => 'booleen', 'defaut' => false, 'portee' => 'phase',
            'visible_si' => 'handicap_actif', 'rg' => ['RG-74'],
        ],
        'pas_dynamique' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Pas du handicap dynamique',
            'domaine' => 'entier', 'defaut' => 1, 'portee' => 'phase',
            'visible_si' => 'dynamique', 'rg' => ['RG-74'],
        ],
        'methode_double' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Méthode pour le double',
            'domaine' => ['moyenne', 'fort_ajuste', 'somme', 'classement_dedie'],
            'defaut' => 'moyenne', 'portee' => 'tournoi',
            'visible_si' => 'type_entite in double,duo,equipe', 'rg' => ['RG-70', 'RG-71'],
        ],
        'fonction_ajustement' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Fonction d\'ajustement',
            'domaine' => 'table', 'defaut' => null, 'portee' => 'tournoi',
            'visible_si' => 'methode_double = fort_ajuste',
        ],
        'arrondi_paire' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Arrondi des forces de paire',
            'domaine' => ['differe', 'immediat'], 'defaut' => 'differe', 'portee' => 'tournoi',
            'visible_si' => 'type_entite in double,duo', 'rg' => ['RG-72'],
        ],
        'handicap_partie_decisive' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Handicap de la partie décisive',
            'domaine' => ['identique', 'sans', 'bareme_dedie'], 'defaut' => 'identique',
            'portee' => 'phase', 'visible_si' => 'type_entite = duo', 'rg' => ['RG-76'],
        ],
        'conversion_mixte' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Conversion mixte',
            'domaine' => ['table_equivalence', 'bonus_fixe', 'bareme_mixte', 'aucune'],
            'defaut' => 'table_equivalence', 'portee' => 'tournoi',
            'visible_si' => 'mixite = mixte_impose',
        ],
        'bonus_mixte' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Bonus mixte',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'tournoi',
            'visible_si' => 'conversion_mixte = bonus_fixe',
        ],
        'rang_non_classe' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Rang des non classés',
            'domaine' => 'entier', 'defaut' => 0, 'portee' => 'tournoi',
            'visible_si' => 'handicap_actif',
        ],
        'contraintes_de_jeu' => [
            'bloc' => self::BLOC_HANDICAP, 'libelle' => 'Contraintes de jeu',
            'domaine' => 'texte', 'defaut' => null, 'portee' => 'phase',
        ],

        // --- C.10 EXECUTION -----------------------------------------
        'score_forfait' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Score attribué au forfait',
            'domaine' => 'texte', 'defaut' => '3-0', 'portee' => 'tournoi',
        ],
        'points_forfait_detail' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Détail des points en forfait',
            'domaine' => ['33-0', '11-0_par_manche', 'aucun'], 'defaut' => '33-0',
            'portee' => 'tournoi',
        ],
        'forfait_avant_debut' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Forfait avant le début',
            'domaine' => ['defaite_toutes_parties', 'retrait_du_classement'],
            'defaut' => 'defaite_toutes_parties', 'portee' => 'tournoi',
        ],
        'forfait_en_cours' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Forfait en cours d\'épreuve',
            'domaine' => ['parties_jouees_maintenues', 'annulation_totale'],
            'defaut' => 'parties_jouees_maintenues', 'portee' => 'tournoi',
        ],
        'abandon_en_cours_de_partie' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Abandon en cours de partie',
            'domaine' => ['score_acquis_conserve', 'remise_a_zero'],
            'defaut' => 'score_acquis_conserve', 'portee' => 'tournoi', 'rg' => ['RG-81'],
        ],
        'propagation_forfait_tableau' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Propagation du forfait en tableau',
            'domaine' => ['premier_tour_seulement', 'tous_les_tours', 'aucune'],
            'defaut' => 'premier_tour_seulement', 'portee' => 'phase', 'rg' => ['RG-83'],
        ],
        'wo_compte_dans_classement_officiel' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'WO compté au classement officiel',
            'domaine' => 'booleen', 'defaut' => false, 'portee' => 'tournoi',
        ],
        'remplacement_joueur' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Remplacement de joueur',
            'domaine' => ['interdit', 'avant_debut', 'libre'], 'defaut' => 'avant_debut',
            'portee' => 'tournoi',
        ],
        'arrivee_tardive' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Arrivée tardive',
            'domaine' => ['forfait', 'integration_poule_en_cours', 'report'],
            'defaut' => 'forfait', 'portee' => 'tournoi',
        ],
        'depart_anticipe' => [
            'bloc' => self::BLOC_EXECUTION, 'libelle' => 'Départ anticipé',
            'domaine' => ['forfait_parties_restantes', 'retrait_resultats'],
            'defaut' => 'forfait_parties_restantes', 'portee' => 'tournoi',
        ],

        // --- C.11 PLANIFICATION -------------------------------------
        'nb_tables' => [
            'bloc' => self::BLOC_PLANIF, 'libelle' => 'Nombre de tables',
            'domaine' => 'entier', 'defaut' => null, 'portee' => 'tournoi',
            'rg' => ['RG-91'],
        ],
        'tables_reservees' => [
            'bloc' => self::BLOC_PLANIF, 'libelle' => 'Tables réservées',
            'domaine' => 'table', 'defaut' => null, 'portee' => 'phase',
        ],
        'strategie_lancement' => [
            'bloc' => self::BLOC_PLANIF, 'libelle' => 'Stratégie de lancement',
            'domaine' => ['sequentielle', 'parallele_max', 'par_poule', 'manuelle'],
            'defaut' => 'parallele_max', 'portee' => 'phase',
        ],
        'repos_min_entre_parties' => [
            'bloc' => self::BLOC_PLANIF, 'libelle' => 'Repos minimum (minutes)',
            'domaine' => 'entier', 'defaut' => 5, 'portee' => 'tournoi', 'rg' => ['RG-90'],
        ],
        'parties_simultanees_par_joueur' => [
            'bloc' => self::BLOC_PLANIF, 'libelle' => 'Parties simultanées par joueur',
            'domaine' => 'entier', 'defaut' => 1, 'portee' => 'tournoi', 'rg' => ['RG-90'],
        ],
        'duree_estimee_partie' => [
            'bloc' => self::BLOC_PLANIF, 'libelle' => 'Durée estimée d\'une partie',
            'domaine' => 'entier', 'defaut' => 'auto', 'portee' => 'phase', 'rg' => ['RG-91'],
        ],
        'heure_limite' => [
            'bloc' => self::BLOC_PLANIF, 'libelle' => 'Heure limite',
            'domaine' => 'texte', 'defaut' => null, 'portee' => 'tournoi', 'rg' => ['RG-91'],
        ],
        'arbitrage_designe' => [
            'bloc' => self::BLOC_PLANIF, 'libelle' => 'Désignation de l\'arbitre',
            'domaine' => ['joueur_de_la_poule', 'perdant_precedent', 'liste'],
            'defaut' => 'joueur_de_la_poule', 'portee' => 'phase',
        ],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PARAMETRES);
    }

    public static function existe(string $code): bool
    {
        return isset(self::PARAMETRES[$code]);
    }

    /**
     * @return array{bloc:string, libelle:string, domaine:string|list<string>,
     *               defaut:mixed, portee:string, visible_si?:string, rg?:list<string>}
     */
    public static function parametre(string $code): array
    {
        if (!isset(self::PARAMETRES[$code])) {
            throw new InvalidArgumentException(sprintf('Parametre inconnu : %s.', $code));
        }

        return self::PARAMETRES[$code];
    }

    /**
     * Valeur par defaut d'un parametre.
     *
     * Aucun defaut n'est ecrit ailleurs : quand une valeur change, elle
     * change ici et partout a la fois.
     */
    public static function defaut(string $code): mixed
    {
        return self::parametre($code)['defaut'];
    }

    /** Tous les defauts, sous forme d'un tableau de configuration. */
    public static function defauts(): array
    {
        $defauts = [];

        foreach (self::PARAMETRES as $code => $p) {
            if ($p['defaut'] !== null) {
                $defauts[$code] = $p['defaut'];
            }
        }

        return $defauts;
    }

    /**
     * La valeur appartient-elle au domaine du parametre ?
     *
     * Les domaines scalaires sont verifies par type ; les domaines
     * enumeres par appartenance. Les valeurs speciales 'auto',
     * 'equilibree' et 'infini' sont admises partout ou le catalogue les
     * donne comme defaut.
     */
    public static function valeurAdmise(string $code, mixed $valeur): bool
    {
        $p = self::parametre($code);

        if ($valeur === null) {
            return true; // non renseigne : le defaut ou l'heritage prendra le relais
        }

        if (in_array($valeur, ['auto', 'equilibree', 'infini'], true)) {
            return in_array($p['defaut'], ['auto', 'equilibree', 'infini'], true)
                || $p['domaine'] === 'entier';
        }

        if (is_array($p['domaine'])) {
            return in_array($valeur, $p['domaine'], true);
        }

        return match ($p['domaine']) {
            'entier'  => is_int($valeur),
            'decimal' => is_int($valeur) || is_float($valeur),
            'booleen' => is_bool($valeur),
            'liste', 'table' => is_array($valeur),
            default   => true, // texte, date, reference, expression
        };
    }

    /**
     * Parametres d'un bloc, dans l'ordre du catalogue.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function bloc(string $bloc): array
    {
        return array_filter(
            self::PARAMETRES,
            static fn (array $p): bool => $p['bloc'] === $bloc
        );
    }

    /**
     * Le catalogue tel que le consomme l'ecran de definition.
     *
     * Une seule source pour le moteur et pour le formulaire : un
     * parametre ajoute ici apparait dans l'ecran sans toucher au
     * gabarit.
     */
    public static function pourEcran(): array
    {
        $sortie = [];

        foreach (self::BLOCS as $bloc => $titre) {
            $champs = [];

            foreach (self::bloc($bloc) as $code => $p) {
                $champs[] = [
                    'code'       => $code,
                    'libelle'    => $p['libelle'],
                    'domaine'    => $p['domaine'],
                    'defaut'     => $p['defaut'],
                    'portee'     => $p['portee'],
                    'visible_si' => $p['visible_si'] ?? null,
                    'rg'         => $p['rg'] ?? [],
                ];
            }

            $sortie[] = ['bloc' => $bloc, 'titre' => $titre, 'champs' => $champs];
        }

        return $sortie;
    }

    public static function enJson(): string
    {
        return json_encode(self::pourEcran(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '[]';
    }
}
