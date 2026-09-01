-- =====================================================================
--  Module Tournois — Royal Mickey Club Falisolle
--  03_donnees_mbn.sql : la configuration reelle du club
--
--  A executer APRES 01_reference.sql et 02_tournoi.sql, sur la base
--  mickey_tournois_moteur.
--
--  Toutes les valeurs de ce fichier proviennent de la configuration
--  existante du club, verifiee ligne a ligne :
--
--    - les 18 echelons AFTT, repris a l'identique de la table
--      `classement` de mickey_tournois_dev ;
--    - le bareme de handicap, dont la formule reproduit EXACTEMENT les
--      324 cellules de la table `handicap` (verifie sur les 35 ecarts
--      possibles, de -17 a +17) ;
--    - la grille de points de la feuille REPARTITION DES POINTS.
--
--  Rien n'a ete invente. La ou une valeur manquait, elle est signalee
--  en commentaire plutot que devinee.
--
--  MariaDB 10.11 · utf8mb4 · InnoDB
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Saison
-- ---------------------------------------------------------------------

INSERT INTO saison (code, libelle, date_debut, date_fin, courante) VALUES
    ('2025-2026', 'Saison 2025-2026', '2025-09-01', '2026-06-30', 1);

SET @saison := LAST_INSERT_ID();

-- ---------------------------------------------------------------------
-- Referentiel AFTT
--
--   `sens = rang_haut_fort` : NC vaut 0, A vaut 17. C'est le piege du
--   §12.2, et la raison pour laquelle ce champ est obligatoire — une
--   inversion produirait des handicaps exactement opposes sans jamais
--   lever la moindre erreur.
-- ---------------------------------------------------------------------

INSERT INTO referentiel_classement (code, libelle, nature, sens, saison_id, actif) VALUES
    ('aftt', 'Classement AFTT', 'ordinal', 'rang_haut_fort', @saison, 1);

SET @aftt := LAST_INSERT_ID();

INSERT INTO echelon_classement (referentiel_id, code, libelle, rang) VALUES
    (@aftt, 'NC', 'Non classé',  0),
    (@aftt, 'E6', 'E6',          1),
    (@aftt, 'E4', 'E4',          2),
    (@aftt, 'E2', 'E2',          3),
    (@aftt, 'E0', 'E0',          4),
    (@aftt, 'D6', 'D6',          5),
    (@aftt, 'D4', 'D4',          6),
    (@aftt, 'D2', 'D2',          7),
    (@aftt, 'D0', 'D0',          8),
    (@aftt, 'C6', 'C6',          9),
    (@aftt, 'C4', 'C4',         10),
    (@aftt, 'C2', 'C2',         11),
    (@aftt, 'C0', 'C0',         12),
    (@aftt, 'B6', 'B6',         13),
    (@aftt, 'B4', 'B4',         14),
    (@aftt, 'B2', 'B2',         15),
    (@aftt, 'B0', 'B0',         16),
    (@aftt, 'A',  'A',          17);

-- ---------------------------------------------------------------------
-- Bareme de handicap MbN
--
--   La formule `sign(e)*min(8; abs(e)/2+1)` avec arrondi inferieur
--   reproduit la matrice du club sur les 35 ecarts possibles. Elle a ete
--   confrontee cellule par cellule a la table `handicap` : zero
--   divergence.
--
--   La matrice est parfaitement antisymetrique et ne depend que de
--   l'ecart de rangs — aucun cas particulier, y compris pour les non
--   classes. C'est ce qui autorise le passage d'une representation par
--   PAIRE a une representation par ECART sans aucune perte.
--
--   `application = par_manche` : le handicap est accorde au debut de
--   chaque manche, pas une seule fois par partie.
-- ---------------------------------------------------------------------

INSERT INTO bareme_handicap (
    code, libelle, referentiel_id, mode_calcul, formule,
    plafond, plancher, arrondi, application, methode_double, arrondi_paire,
    rang_non_classe, saison_id, version
) VALUES (
    'mbn', 'Handicap Mickey By Night', @aftt, 'formule',
    'sign(e)*min(8; abs(e)/2+1)',
    8, 0, 'inferieur', 'par_manche', 'moyenne', 'differe',
    0, @saison, 1
);

SET @handicap := LAST_INSERT_ID();

-- La table equivalente, generee depuis la formule. Elle n'est PAS
-- utilisee pour le calcul (mode_calcul vaut 'formule') : elle sert
-- d'apercu imprimable et de filet de securite. Si un jour la formule
-- devait etre modifiee, ces valeurs restent la trace de ce que le club
-- appliquait reellement.
INSERT INTO bareme_handicap_valeur (bareme_id, ecart, handicap) VALUES
    (@handicap, -17, -8), (@handicap, -16, -8), (@handicap, -15, -8),
    (@handicap, -14, -8), (@handicap, -13, -7), (@handicap, -12, -7),
    (@handicap, -11, -6), (@handicap, -10, -6), (@handicap,  -9, -5),
    (@handicap,  -8, -5), (@handicap,  -7, -4), (@handicap,  -6, -4),
    (@handicap,  -5, -3), (@handicap,  -4, -3), (@handicap,  -3, -2),
    (@handicap,  -2, -2), (@handicap,  -1, -1), (@handicap,   0,  0),
    (@handicap,   1,  1), (@handicap,   2,  2), (@handicap,   3,  2),
    (@handicap,   4,  3), (@handicap,   5,  3), (@handicap,   6,  4),
    (@handicap,   7,  4), (@handicap,   8,  5), (@handicap,   9,  5),
    (@handicap,  10,  6), (@handicap,  11,  6), (@handicap,  12,  7),
    (@handicap,  13,  7), (@handicap,  14,  8), (@handicap,  15,  8),
    (@handicap,  16,  8), (@handicap,  17,  8);

-- ---------------------------------------------------------------------
-- Formats de partie
--
--   Les trois formats de l'enum `phase.format_match` de l'ancien modele.
--   `seuil_egalite` et `changement_cote_decisive` restent NULL : RG-40
--   impose qu'ils suivent `points_par_manche`, le code les derive.
-- ---------------------------------------------------------------------

INSERT INTO format_partie (
    code, libelle, type, nb_manches, points_par_manche,
    deux_points_ecart, arbitrage, saison_id
) VALUES
    ('3_sets_secs', '3 manches sèches', 'manches_seches', 3, 11, 1, 'joueur_suivant', 0),
    ('2_sets_gagnants', '2 manches gagnantes (au meilleur des 3)',
     'manches_gagnantes', 2, 11, 1, 'joueur_suivant', 0),
    ('3_sets_gagnants', '3 manches gagnantes (au meilleur des 5)',
     'manches_gagnantes', 3, 11, 1, 'joueur_suivant', 0);

-- ---------------------------------------------------------------------
-- Regles de classement
--
--   DEUX CASCADES, parce que les criteres dependent du format.
--
--   En MANCHES SECHES, toute partie va au bout et compte le meme nombre
--   de manches : le nombre de VICTOIRES ne discrimine pas, ce sont les
--   manches qui font foi. Un joueur peut donc terminer devant un
--   adversaire ayant gagne une partie de plus que lui.
--
--   En MANCHES GAGNANTES, une partie s'arrete des qu'elle est gagnee :
--   les victoires reprennent leur sens et passent en tete.
--
--   Dans les deux cas, la cascade se termine par CLASSEMENT AFTT puis
--   ALPHABETIQUE. C'est ce qui satisfait RG-51 et rend impossible tout
--   ex aequo residuel — donc tout departage manuel. La table
--   `poule_participant` de l'ancien modele porte `place_forcee`,
--   `place_forcee_par` et `place_forcee_le` : ces colonnes n'ont plus
--   d'objet ici, l'organisateur n'a plus a trancher.
-- ---------------------------------------------------------------------

INSERT INTO regle_classement (
    code, libelle, retrait_iteratif, interdire_ex_aequo,
    bareme_rencontre, points_forfait, points_defaite_jouee, saison_id, version
) VALUES
    ('mbn_poule_seches', 'Poule MbN — manches sèches',
     1, 1, '2-1-0', 0, 1, 0, 1),
    ('mbn_poule_gagnantes', 'Poule MbN — manches gagnantes',
     1, 1, '2-1-0', 0, 1, 0, 1),
    ('mbn_general', 'Classement général après poules',
     0, 1, '2-1-0', 0, 1, 0, 1);

SET @r_seches    := (SELECT id FROM regle_classement WHERE code = 'mbn_poule_seches');
SET @r_gagnantes := (SELECT id FROM regle_classement WHERE code = 'mbn_poule_gagnantes');
SET @r_general   := (SELECT id FROM regle_classement WHERE code = 'mbn_general');

-- Manches seches : pas de critere « victoires ».
INSERT INTO critere_classement (regle_id, ordre, critere, portee) VALUES
    (@r_seches, 1, 'manches_gagnees',       'toute_la_poule'),
    (@r_seches, 2, 'diff_manches',          'toute_la_poule'),
    (@r_seches, 3, 'confrontation_directe', 'entre_ex_aequo'),
    (@r_seches, 4, 'classement_officiel',   'entre_ex_aequo'),
    (@r_seches, 5, 'alphabetique',          'entre_ex_aequo');

-- Manches gagnantes : les victoires en tete.
INSERT INTO critere_classement (regle_id, ordre, critere, portee) VALUES
    (@r_gagnantes, 1, 'victoires',             'toute_la_poule'),
    (@r_gagnantes, 2, 'manches_gagnees',       'toute_la_poule'),
    (@r_gagnantes, 3, 'diff_manches',          'toute_la_poule'),
    (@r_gagnantes, 4, 'confrontation_directe', 'entre_ex_aequo'),
    (@r_gagnantes, 5, 'classement_officiel',   'entre_ex_aequo'),
    (@r_gagnantes, 6, 'alphabetique',          'entre_ex_aequo');

-- Classement general inter-poules.
--
--   La confrontation directe est ABSENTE, et ce n'est pas un oubli :
--   deux joueurs de poules differentes ne se sont pas rencontres.
--
--   L'ordre « manches gagnees AVANT difference de manches » est un choix
--   du club, publie sur la feuille ATTRIBUTION DES POINTS. Il doit etre
--   reproduit tel quel.
--
--   LE POINT OUVERT DU §7.6 EST TRAITE PAR `departage_manches_auto`.
--   Comparer des manches gagnees suppose que chaque partie en compte le
--   meme nombre — vrai en manches seches, faux en manches gagnantes, ou
--   celui qui l'emporte peniblement 3-2 en accumule plus que celui qui
--   ecrase 3-0. Plutot que de figer un critere inadapte a l'un des deux
--   formats, ce critere lit le format de la phase A L'EXECUTION (RG-53)
--   et choisit lui-meme entre manches gagnees et ratio.
--
--   La question de fond reste ouverte — quel critere exactement en
--   manches gagnantes — mais elle ne bloque plus le fonctionnement, et
--   la reponse se changera a un seul endroit le jour ou elle sera
--   tranchee par simulation sur des donnees reelles.
INSERT INTO critere_classement (regle_id, ordre, critere, portee) VALUES
    (@r_general, 1, 'place_poule',              'toute_la_poule'),
    (@r_general, 2, 'victoires',                 'toute_la_poule'),
    (@r_general, 3, 'departage_manches_auto',   'toute_la_poule'),
    (@r_general, 4, 'classement_officiel',       'entre_ex_aequo'),
    (@r_general, 5, 'alphabetique',              'entre_ex_aequo');

-- ---------------------------------------------------------------------
-- Bareme de points
--
--   Repris de la feuille REPARTITION DES POINTS. Le mois qui figure sur
--   la feuille n'est qu'un titre saisi a la main : la grille ne change
--   pas d'un mois a l'autre.
--
--   `cumul = somme_toutes_journees` est un CHOIX DELIBERE du club, pas
--   un defaut a corriger : recompenser la regularite est l'objectif
--   affiche. Le joueur present cinq soirees doit devancer celui qui n'en
--   fait que trois. `n_meilleures` reste donc NULL.
--
--   A NOTER — le seuil entre les deux tableaux est volontairement
--   brutal. Sorti au premier tour du tableau final, un joueur marque 35
--   points ; vainqueur de la consolante, il en marque 25. La
--   qualification pese donc plus lourd que tout parcours en consolante.
-- ---------------------------------------------------------------------

INSERT INTO bareme_points (code, libelle, definition_json, cumul, n_meilleures, saison_id, version)
VALUES (
    'mbn',
    'Barème Mickey By Night',
    JSON_OBJECT(
        'participation',   5,
        'victoire_poule',  1,
        'bonus_1er_poule', 5,
        'parcours', JSON_OBJECT(
            'consolation', JSON_OBJECT(
                'non_qualifie',  2,
                'barragiste',    3,
                '8e',            5,
                'quart',        10,
                'demie',        15,
                'finaliste',    20,
                'vainqueur',    25
            ),
            'tableau_final', JSON_OBJECT(
                '8e',           35,
                'quart',        45,
                'demie',        65,
                'finaliste',   100,
                'vainqueur',   120
            )
        )
    ),
    'somme_toutes_journees', NULL, @saison, 1
);

-- ---------------------------------------------------------------------
-- Systeme de rencontre — le simple
--
--   Le MbN se joue en simple : une rencontre porte une seule partie.
--   Les systemes double, duo et par equipes viendront quand le club
--   jouera reellement ces formules (§12.4).
-- ---------------------------------------------------------------------

INSERT INTO systeme_rencontre (
    code, libelle, nb_joueurs_min, nb_joueurs_max, regle_arret, affectation_roles, saison_id
) VALUES
    ('simple', 'Simple — une partie par rencontre', 1, 1, 'toutes_parties', 'par_classement', 0);

SET @simple := LAST_INSERT_ID();

INSERT INTO systeme_rencontre_partie (systeme_id, ordre, camp_a_role, camp_b_role, type_partie)
VALUES (@simple, 1, 'titulaire', 'titulaire', 'simple');

-- ---------------------------------------------------------------------
-- Verification
-- ---------------------------------------------------------------------

SELECT 'saison'          AS objet, COUNT(*) AS lignes FROM saison
UNION ALL SELECT 'echelons AFTT',        COUNT(*) FROM echelon_classement
UNION ALL SELECT 'handicaps par ecart',  COUNT(*) FROM bareme_handicap_valeur
UNION ALL SELECT 'formats de partie',    COUNT(*) FROM format_partie
UNION ALL SELECT 'regles de classement', COUNT(*) FROM regle_classement
UNION ALL SELECT 'criteres',             COUNT(*) FROM critere_classement
UNION ALL SELECT 'baremes de points',    COUNT(*) FROM bareme_points;
