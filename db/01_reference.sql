-- =====================================================================
--  Module Tournois — Royal Mickey Club Falisolle
--  01_reference.sql : les tables de CONFIGURATION VERSIONNEE
--
--  Ce fichier couvre les points 1 a 5 et 8 de l'annexe C.13.
--
--  Le principe qui gouverne tout le fichier tient en une phrase du §9.1 :
--  on stocke les faits observes ET la configuration qui les a produits ;
--  on ne stocke jamais un classement comme donnee primaire.
--
--  D'ou la regle de versionnement, qui est la contrainte la plus
--  structurante du schema : un tournoi REFERENCE une version figee de
--  chaque table de reference. Modifier le bareme de handicap de la
--  saison 2026-2027 ne doit jamais changer le classement d'une soiree
--  de 2023. C'est la seule facon de pouvoir rejouer une journee passee
--  et retrouver exactement le meme resultat.
--
--  MariaDB 10.11 · utf8mb4 · InnoDB
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- Saison — l'axe de versionnement de tout le reste
-- ---------------------------------------------------------------------

CREATE TABLE saison (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(16)  NOT NULL COMMENT 'ex. 2026-2027',
    libelle     VARCHAR(64)  NOT NULL,
    date_debut  DATE         NOT NULL,
    date_fin    DATE         NOT NULL,
    courante    TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_saison_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Referentiel de classement — §9.4
--
--    `sens` est le champ qui rattrape le piege du §12.2 : selon le
--    referentiel, un rang eleve designe un joueur fort (AFTT : NC=0…A=17)
--    ou faible (jeu de paume, golf). Une inversion non detectee produit
--    des handicaps aberrants sans jamais lever d'erreur — d'ou un champ
--    obligatoire plutot qu'une convention implicite.
-- ---------------------------------------------------------------------

CREATE TABLE referentiel_classement (
    id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code      VARCHAR(32)  NOT NULL COMMENT 'aftt, fftt, elo_interne',
    libelle   VARCHAR(96)  NOT NULL,
    nature    ENUM('ordinal','cardinal')                 NOT NULL DEFAULT 'ordinal',
    sens      ENUM('rang_haut_fort','rang_haut_faible')  NOT NULL DEFAULT 'rang_haut_fort',
    saison_id SMALLINT UNSIGNED NOT NULL,
    actif     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_referentiel (code, saison_id),
    CONSTRAINT fk_referentiel_saison FOREIGN KEY (saison_id) REFERENCES saison (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE echelon_classement (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    referentiel_id  SMALLINT UNSIGNED NOT NULL,
    code            VARCHAR(8)  NOT NULL COMMENT 'NC, E6, D4, A…',
    libelle         VARCHAR(48) NOT NULL,
    rang            SMALLINT    NOT NULL COMMENT 'AFTT : NC=0 … A=17',
    valeur          INT         NULL COMMENT 'si nature = cardinal',
    PRIMARY KEY (id),
    UNIQUE KEY uk_echelon (referentiel_id, code),
    KEY ix_echelon_rang (referentiel_id, rang),
    CONSTRAINT fk_echelon_referentiel FOREIGN KEY (referentiel_id)
        REFERENCES referentiel_classement (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table d'equivalence Messieurs / Dames (§6.5, approche 1).
-- C'est la solution federale et la plus defendable devant les joueurs :
-- on convertit tout vers une echelle unique, puis on applique le bareme
-- normal. Versionnee par saison, car la table FRBTT evolue.
CREATE TABLE equivalence_classement (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    referentiel_source_id SMALLINT UNSIGNED NOT NULL,
    echelon_source_id     INT UNSIGNED NOT NULL,
    referentiel_cible_id  SMALLINT UNSIGNED NOT NULL,
    echelon_cible_id      INT UNSIGNED NOT NULL,
    saison_id             SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_equivalence (echelon_source_id, referentiel_cible_id, saison_id),
    CONSTRAINT fk_equiv_src_ref FOREIGN KEY (referentiel_source_id) REFERENCES referentiel_classement (id),
    CONSTRAINT fk_equiv_src_ech FOREIGN KEY (echelon_source_id)     REFERENCES echelon_classement (id),
    CONSTRAINT fk_equiv_cib_ref FOREIGN KEY (referentiel_cible_id)  REFERENCES referentiel_classement (id),
    CONSTRAINT fk_equiv_cib_ech FOREIGN KEY (echelon_cible_id)      REFERENCES echelon_classement (id),
    CONSTRAINT fk_equiv_saison  FOREIGN KEY (saison_id)             REFERENCES saison (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 1. Format de partie — bloc C.6
--
--    RG-40 : `seuil_egalite` et `changement_cote` DOIVENT suivre
--    `points_par_manche`. On les stocke en NULL par defaut pour laisser
--    le code les deriver ; une valeur explicite est une surcharge
--    assumee, pas un oubli.
-- ---------------------------------------------------------------------

CREATE TABLE format_partie (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                      VARCHAR(48) NOT NULL,
    libelle                   VARCHAR(96) NOT NULL,
    type                      ENUM('manches_gagnantes','manches_seches','manche_unique','au_temps','score_cible')
                              NOT NULL DEFAULT 'manches_gagnantes',
    nb_manches                TINYINT UNSIGNED NOT NULL DEFAULT 3,
    points_par_manche         SMALLINT UNSIGNED NOT NULL DEFAULT 11,
    deux_points_ecart         TINYINT(1) NOT NULL DEFAULT 1,
    plafond_manche            SMALLINT UNSIGNED NULL,
    changement_service        TINYINT UNSIGNED NOT NULL DEFAULT 2,
    changement_service_egalite TINYINT UNSIGNED NOT NULL DEFAULT 1,
    seuil_egalite             SMALLINT UNSIGNED NULL COMMENT 'NULL = points_par_manche - 1 (RG-40)',
    changement_cote_decisive  SMALLINT UNSIGNED NULL COMMENT 'NULL = points_par_manche / 2 (RG-40)',
    acceleration              ENUM('interdite','autorisee','imposee') NOT NULL DEFAULT 'autorisee',
    acceleration_apres        TINYINT UNSIGNED NOT NULL DEFAULT 10,
    duree_minutes             SMALLINT UNSIGNED NULL COMMENT 'si type = au_temps',
    arbitrage                 ENUM('joueur_suivant','designe','aucun') NOT NULL DEFAULT 'joueur_suivant',
    saison_id                 SMALLINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_format (code, saison_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Systeme de rencontre — bloc C.3
--
--    RG-10 : la sequence des parties d'une rencontre est UNE DONNEE,
--    jamais du code. Ajouter le systeme olympique 2028 doit etre une
--    insertion, pas un deploiement. C'est pourquoi l'ordre des parties
--    vit dans une table fille et non dans un `switch`.
-- ---------------------------------------------------------------------

CREATE TABLE systeme_rencontre (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code              VARCHAR(48) NOT NULL,
    libelle           VARCHAR(96) NOT NULL,
    nb_joueurs_min    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    nb_joueurs_max    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    regle_arret       ENUM('a_l_acquis','toutes_parties') NOT NULL DEFAULT 'a_l_acquis',
    affectation_roles ENUM('libre','par_classement','liste_forces','choix_capitaine')
                      NOT NULL DEFAULT 'par_classement',
    saison_id         SMALLINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_systeme (code, saison_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE systeme_rencontre_partie (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    systeme_id    INT UNSIGNED NOT NULL,
    ordre         TINYINT UNSIGNED NOT NULL,
    camp_a_role   VARCHAR(16) NOT NULL COMMENT 'A, B, C… ou faible / fort / paire',
    camp_b_role   VARCHAR(16) NOT NULL,
    type_partie   ENUM('simple','double') NOT NULL DEFAULT 'simple',
    conditionnelle TINYINT(1) NOT NULL DEFAULT 0,
    condition_expr VARCHAR(128) NULL COMMENT 'ex. victoires_a = 1 et victoires_b = 1',
    PRIMARY KEY (id),
    UNIQUE KEY uk_systeme_partie (systeme_id, ordre),
    CONSTRAINT fk_srp_systeme FOREIGN KEY (systeme_id)
        REFERENCES systeme_rencontre (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Regle de classement — bloc C.7, §7.8
--
--    RG-50 : l'algorithme de departage est UNIQUE, parametre par cette
--    table. Il n'existe pas de code « departage FFTT » ou « departage
--    FRBTT » — il existe deux lignes de `regle_classement` et leurs
--    criteres. C'est la simplification la plus rentable du projet.
-- ---------------------------------------------------------------------

CREATE TABLE regle_classement (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code               VARCHAR(48) NOT NULL COMMENT 'ittf, fftt, frbtt, mbn_poule…',
    libelle            VARCHAR(96) NOT NULL,
    retrait_iteratif   TINYINT(1) NOT NULL DEFAULT 1,
    interdire_ex_aequo TINYINT(1) NOT NULL DEFAULT 1,
    bareme_rencontre   VARCHAR(24) NOT NULL DEFAULT '2-1-0',
    points_forfait     TINYINT NOT NULL DEFAULT 0 COMMENT 'RG-80 : 0, jamais 1',
    points_defaite_jouee TINYINT NOT NULL DEFAULT 1,
    saison_id          SMALLINT UNSIGNED NULL,
    version            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_regle (code, saison_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE critere_classement (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    regle_id INT UNSIGNED NOT NULL,
    ordre    TINYINT UNSIGNED NOT NULL,
    critere  VARCHAR(32) NOT NULL COMMENT 'enum Critere : 22 valeurs',
    portee   ENUM('toute_la_poule','entre_ex_aequo') NOT NULL DEFAULT 'toute_la_poule',
    PRIMARY KEY (id),
    UNIQUE KEY uk_critere (regle_id, ordre),
    CONSTRAINT fk_critere_regle FOREIGN KEY (regle_id)
        REFERENCES regle_classement (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Bareme de points — bloc C.8
--
--    RG-61 : `cumul = n_meilleures_journees` impose de conserver le
--    detail par journee, jamais seulement le total. Le detail vit dans
--    `classement_cumule` (02_faits.sql) ; ici on ne stocke que la regle.
-- ---------------------------------------------------------------------

CREATE TABLE bareme_points (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(48) NOT NULL,
    libelle         VARCHAR(96) NOT NULL,
    definition_json JSON NOT NULL COMMENT 'participation, victoire, bonus, tables place->points',
    cumul           ENUM('somme_toutes_journees','n_meilleures_journees','moyenne')
                    NOT NULL DEFAULT 'somme_toutes_journees',
    n_meilleures    TINYINT UNSIGNED NULL,
    saison_id       SMALLINT UNSIGNED NOT NULL,
    version         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bareme_points (code, saison_id, version),
    CONSTRAINT fk_bareme_points_saison FOREIGN KEY (saison_id) REFERENCES saison (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Bareme de handicap — bloc C.9
--
--    `formule` est une EXPRESSION saisie, evaluee par un analyseur
--    descendant recursif, jamais par eval(). Une formule est une donnee
--    de formulaire : elle ne doit pas pouvoir devenir du code.
-- ---------------------------------------------------------------------

CREATE TABLE bareme_handicap (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(48) NOT NULL,
    libelle             VARCHAR(96) NOT NULL,
    referentiel_id      SMALLINT UNSIGNED NOT NULL,
    mode_calcul         ENUM('formule','table') NOT NULL DEFAULT 'formule',
    formule             VARCHAR(255) NULL COMMENT 'ex. sign(e)*min(8; abs(e)/2+1)',
    plafond             SMALLINT NOT NULL DEFAULT 8,
    plancher            SMALLINT NOT NULL DEFAULT 0,
    arrondi             ENUM('inferieur','superieur','proche','bancaire') NOT NULL DEFAULT 'inferieur',
    avantage_residuel   SMALLINT NOT NULL DEFAULT 0,
    application         ENUM('par_manche','une_fois','en_manches') NOT NULL DEFAULT 'par_manche',
    dynamique           TINYINT(1) NOT NULL DEFAULT 0,
    pas_dynamique       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    methode_double      ENUM('moyenne','fort_ajuste','somme','classement_dedie') NOT NULL DEFAULT 'moyenne',
    arrondi_paire       ENUM('differe','immediat') NOT NULL DEFAULT 'differe' COMMENT 'RG-72',
    handicap_decisive   ENUM('identique','sans','bareme_dedie') NOT NULL DEFAULT 'identique',
    conversion_mixte    ENUM('table_equivalence','bonus_fixe','bareme_mixte','aucune')
                        NOT NULL DEFAULT 'table_equivalence',
    bonus_mixte         SMALLINT NULL,
    rang_non_classe     SMALLINT NOT NULL DEFAULT 0,
    contraintes_de_jeu  TEXT NULL COMMENT 'non calculable, imprime sur la feuille (§6.2)',
    saison_id           SMALLINT UNSIGNED NOT NULL,
    version             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_bareme_handicap (code, saison_id, version),
    CONSTRAINT fk_bh_referentiel FOREIGN KEY (referentiel_id) REFERENCES referentiel_classement (id),
    CONSTRAINT fk_bh_saison      FOREIGN KEY (saison_id)      REFERENCES saison (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bareme_handicap_valeur (
    bareme_id INT UNSIGNED NOT NULL,
    ecart     SMALLINT NOT NULL,
    handicap  SMALLINT NOT NULL,
    PRIMARY KEY (bareme_id, ecart),
    CONSTRAINT fk_bhv_bareme FOREIGN KEY (bareme_id)
        REFERENCES bareme_handicap (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Methode B du §6.4 : facteur d'ajustement selon l'ecart intra-paire.
-- Decimal et non entier, parce que RG-72 exige de calculer les forces de
-- paires avec au moins une decimale et de n'arrondir qu'a la comparaison.
CREATE TABLE bareme_ajustement_double (
    bareme_id   INT UNSIGNED NOT NULL,
    ecart_intra SMALLINT NOT NULL,
    ajustement  DECIMAL(5,2) NOT NULL,
    PRIMARY KEY (bareme_id, ecart_intra),
    CONSTRAINT fk_bad_bareme FOREIGN KEY (bareme_id)
        REFERENCES bareme_handicap (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
