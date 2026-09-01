-- =====================================================================
--  Module Tournois — Royal Mickey Club Falisolle
--  02_tournoi.sql : la STRUCTURE d'un tournoi et les FAITS observes
--
--  Couvre les points 6 et 7 de l'annexe C.13 (phase, flux_qualification)
--  et les tables de resultats du §9.3.
--
--  Deux idees portent tout le fichier.
--
--  PREMIERE — la separation faits / regles (§9.1). Les tables de ce
--  fichier stockent ce qui S'EST PASSE ; celles de 01_reference.sql
--  stockent les regles qui l'ont produit. Le classement n'apparait
--  qu'une fois, dans `classement_calcule`, et uniquement comme CACHE :
--  il est toujours recalculable, jamais source de verite.
--
--  SECONDE — `flux_qualification` est le point d'orgue de la
--  factorisation. Consolante, barrage, branche des perdants, vies
--  multiples, montees et descentes de criterium sont tous des flux
--  entre phases. Il n'y a pas cinq mecanismes, il y en a un.
--
--  MariaDB 10.11 · utf8mb4 · InnoDB
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Le joueur : identite stable, independante des tournois
-- ---------------------------------------------------------------------

CREATE TABLE joueur (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nom            VARCHAR(64) NOT NULL,
    prenom         VARCHAR(64) NOT NULL,
    licence        VARCHAR(24) NULL,
    date_naissance DATE NULL COMMENT 'critere « avantage au plus jeune » (§7.4)',
    sexe           ENUM('M','F','X') NULL,
    club           VARCHAR(64) NULL COMMENT 'criteres_separation',
    famille        VARCHAR(64) NULL,
    actif          TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_joueur_licence (licence),
    KEY ix_joueur_nom (nom, prenom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historise, jamais ecrase : sans historique, on ne peut pas expliquer
-- pourquoi un handicap valait 4 en novembre et 3 en mars.
CREATE TABLE classement_joueur (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    joueur_id      INT UNSIGNED NOT NULL,
    referentiel_id SMALLINT UNSIGNED NOT NULL,
    echelon_id     INT UNSIGNED NOT NULL,
    date_debut     DATE NOT NULL,
    date_fin       DATE NULL,
    PRIMARY KEY (id),
    KEY ix_cj_joueur (joueur_id, date_debut),
    CONSTRAINT fk_cj_joueur      FOREIGN KEY (joueur_id)      REFERENCES joueur (id),
    CONSTRAINT fk_cj_referentiel FOREIGN KEY (referentiel_id) REFERENCES referentiel_classement (id),
    CONSTRAINT fk_cj_echelon     FOREIGN KEY (echelon_id)     REFERENCES echelon_classement (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Le tournoi — §9.5
--
--    L'etat `modele` n'est pas decoratif : c'est le mecanisme des
--    prereglages (§11). Un prereglage est un tournoi a l'etat `modele`,
--    clonable. Creer un nouveau prereglage devient une operation
--    d'organisateur, pas de developpeur.
-- ---------------------------------------------------------------------

CREATE TABLE tournoi (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code               VARCHAR(48) NOT NULL,
    libelle            VARCHAR(128) NOT NULL,
    saison_id          SMALLINT UNSIGNED NOT NULL,
    date_debut         DATE NULL,
    date_fin           DATE NULL,
    type_entite        ENUM('simple','double','duo','equipe') NOT NULL DEFAULT 'simple',
    referentiel_id     SMALLINT UNSIGNED NULL,
    bareme_points_id   INT UNSIGNED NULL,
    bareme_handicap_id INT UNSIGNED NULL COMMENT 'NULL = sans handicap',
    regle_classement_id INT UNSIGNED NULL,
    format_partie_id   INT UNSIGNED NULL COMMENT 'defaut, surchargeable par phase et par tour',
    systeme_rencontre_id INT UNSIGNED NULL,
    gel_classement     TINYINT(1) NOT NULL DEFAULT 1,
    moment_gel         ENUM('inscription','ouverture','debut_phase') NOT NULL DEFAULT 'inscription',
    graine_tirage      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'un tirage doit etre rejouable',
    visibilite         ENUM('prive','club','public') NOT NULL DEFAULT 'club',
    etat               ENUM('modele','brouillon','ouvert','en_cours','cloture','archive')
                       NOT NULL DEFAULT 'brouillon',
    parametres_json    JSON NULL COMMENT 'surcharges du catalogue, niveau tournoi',
    PRIMARY KEY (id),
    UNIQUE KEY uk_tournoi (code, saison_id),
    KEY ix_tournoi_etat (etat, saison_id),
    CONSTRAINT fk_tournoi_saison   FOREIGN KEY (saison_id)          REFERENCES saison (id),
    CONSTRAINT fk_tournoi_ref      FOREIGN KEY (referentiel_id)     REFERENCES referentiel_classement (id),
    CONSTRAINT fk_tournoi_bp       FOREIGN KEY (bareme_points_id)   REFERENCES bareme_points (id),
    CONSTRAINT fk_tournoi_bh       FOREIGN KEY (bareme_handicap_id) REFERENCES bareme_handicap (id),
    CONSTRAINT fk_tournoi_regle    FOREIGN KEY (regle_classement_id) REFERENCES regle_classement (id),
    CONSTRAINT fk_tournoi_format   FOREIGN KEY (format_partie_id)   REFERENCES format_partie (id),
    CONSTRAINT fk_tournoi_systeme  FOREIGN KEY (systeme_rencontre_id) REFERENCES systeme_rencontre (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- L'inscription : le joueur DANS un tournoi, avec son classement GELE.
-- RG-02 : aucun calcul de handicap ne lit jamais le classement courant.
CREATE TABLE inscription (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournoi_id            INT UNSIGNED NOT NULL,
    joueur_id             INT UNSIGNED NOT NULL,
    echelon_gele_id       INT UNSIGNED NULL,
    rang_gele             SMALLINT NOT NULL DEFAULT 0,
    classement_double_surcharge SMALLINT NULL COMMENT 'methode D du §6.4',
    tete_de_serie         SMALLINT NULL,
    etat                  ENUM('inscrit','present','forfait','abandon','elimine')
                          NOT NULL DEFAULT 'inscrit',
    vies_restantes        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    date_inscription      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_inscription (tournoi_id, joueur_id),
    KEY ix_inscription_rang (tournoi_id, rang_gele),
    CONSTRAINT fk_inscription_tournoi FOREIGN KEY (tournoi_id) REFERENCES tournoi (id) ON DELETE CASCADE,
    CONSTRAINT fk_inscription_joueur  FOREIGN KEY (joueur_id)  REFERENCES joueur (id),
    CONSTRAINT fk_inscription_echelon FOREIGN KEY (echelon_gele_id) REFERENCES echelon_classement (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Le camp : ce qui s'affronte. En simple il compte un membre ; en double
-- deux ; en equipe jusqu'a six. Une paire n'est PAS un joueur virtuel.
CREATE TABLE camp (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournoi_id INT UNSIGNED NOT NULL,
    type       ENUM('simple','paire','duo','equipe') NOT NULL DEFAULT 'simple',
    libelle    VARCHAR(128) NOT NULL,
    PRIMARY KEY (id),
    KEY ix_camp_tournoi (tournoi_id),
    CONSTRAINT fk_camp_tournoi FOREIGN KEY (tournoi_id) REFERENCES tournoi (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RG-13 : l'affectation des roles est figee a la creation de la
-- rencontre. Un reclassement ulterieur ne reordonne pas les rencontres
-- deja creees.
CREATE TABLE camp_membre (
    camp_id        INT UNSIGNED NOT NULL,
    inscription_id INT UNSIGNED NOT NULL,
    role           VARCHAR(16) NOT NULL DEFAULT 'titulaire'
                   COMMENT 'titulaire | faible | fort | A | B | C | relais_1…',
    ordre          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (camp_id, inscription_id),
    CONSTRAINT fk_cm_camp        FOREIGN KEY (camp_id)        REFERENCES camp (id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_inscription FOREIGN KEY (inscription_id) REFERENCES inscription (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Phase — bloc C.4
--
--    `type_phase` inclut `croise` : c'est la lacune Scheveningen de la
--    matrice C.12, comblee. `parametres_json` porte les sous-blocs
--    propres a chaque type plutot que quarante colonnes dont trente-cinq
--    seraient NULL selon le type.
-- ---------------------------------------------------------------------

CREATE TABLE phase (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournoi_id           INT UNSIGNED NOT NULL,
    code                 VARCHAR(48) NOT NULL,
    ordre                TINYINT UNSIGNED NOT NULL,
    libelle              VARCHAR(96) NOT NULL,
    type_phase           ENUM('poules','tableau','consolante','classement_integral',
                              'barrage','suisse','echelle','croise')
                         NOT NULL DEFAULT 'poules',
    obligatoire          TINYINT(1) NOT NULL DEFAULT 1,
    condition_activation VARCHAR(128) NULL COMMENT 'ex. nb_inscrits > 24 (RG-21)',
    format_partie_id     INT UNSIGNED NULL,
    regle_classement_id  INT UNSIGNED NULL COMMENT 'cascade de groupe',
    regle_inter_groupes_id INT UNSIGNED NULL COMMENT 'cascade inter-groupes (§7.6)',
    etat                 ENUM('a_venir','generee','en_cours','close') NOT NULL DEFAULT 'a_venir',
    parametres_json      JSON NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_phase (tournoi_id, code),
    KEY ix_phase_ordre (tournoi_id, ordre),
    CONSTRAINT fk_phase_tournoi FOREIGN KEY (tournoi_id) REFERENCES tournoi (id) ON DELETE CASCADE,
    CONSTRAINT fk_phase_format  FOREIGN KEY (format_partie_id) REFERENCES format_partie (id),
    CONSTRAINT fk_phase_regle   FOREIGN KEY (regle_classement_id) REFERENCES regle_classement (id),
    CONSTRAINT fk_phase_inter   FOREIGN KEY (regle_inter_groupes_id) REFERENCES regle_classement (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE groupe (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phase_id INT UNSIGNED NOT NULL,
    code     VARCHAR(32) NOT NULL COMMENT 'A, B… ou principal / perdants',
    libelle  VARCHAR(64) NOT NULL,
    ordre    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_groupe (phase_id, code),
    CONSTRAINT fk_groupe_phase FOREIGN KEY (phase_id) REFERENCES phase (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Surcharge du format par tour (§4.1) : « poules au meilleur des 3,
-- tableau au meilleur des 5, finale au meilleur des 7 » est legal et
-- c'est la configuration vers laquelle convergent les organisateurs.
CREATE TABLE tour (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phase_id         INT UNSIGNED NOT NULL,
    numero           TINYINT UNSIGNED NOT NULL,
    libelle          VARCHAR(64) NOT NULL,
    format_partie_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tour (phase_id, numero),
    CONSTRAINT fk_tour_phase  FOREIGN KEY (phase_id) REFERENCES phase (id) ON DELETE CASCADE,
    CONSTRAINT fk_tour_format FOREIGN KEY (format_partie_id) REFERENCES format_partie (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE groupe_membre (
    groupe_id      INT UNSIGNED NOT NULL,
    inscription_id INT UNSIGNED NOT NULL,
    position       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (groupe_id, inscription_id),
    CONSTRAINT fk_gm_groupe      FOREIGN KEY (groupe_id)      REFERENCES groupe (id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_inscription FOREIGN KEY (inscription_id) REFERENCES inscription (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Flux de qualification — bloc C.5
--
--    RG-31 : `ordre` tranche quand deux flux selectionnent la meme
--    entite — le plus petit gagne.
--    RG-32 : `non_qualifies` est evalue apres tous les autres flux de
--    la meme source, ce qui permet d'ecrire « les 2 premiers au tableau,
--    tout le reste en consolante » sans enumerer les places.
-- ---------------------------------------------------------------------

CREATE TABLE flux_qualification (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournoi_id        INT UNSIGNED NOT NULL,
    phase_source_id   INT UNSIGNED NULL COMMENT 'NULL = inscriptions',
    phase_cible_id    INT UNSIGNED NOT NULL,
    ordre             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    selecteur         ENUM('place_exacte','places_de_a','meilleurs_n_iemes','non_qualifies',
                           'perdants_tour','vainqueurs_tour','elimines_avec_n_defaites',
                           'top_n_global','montants','descendants','repeches','tous','manuel')
                      NOT NULL,
    parametre         VARCHAR(32) NULL COMMENT 'k, « k1-k2 », « place:combien »…',
    tour_entree_cible VARCHAR(8) NOT NULL DEFAULT 'auto',
    mode_placement    ENUM('tetes_de_serie','croise','miroir','tirage','serpentin','manuel')
                      NOT NULL DEFAULT 'croise',
    regle_ordre_id    INT UNSIGNED NULL COMMENT 'cascade a utiliser, defaut = celle de la source',
    capacite_max      SMALLINT UNSIGNED NULL,
    si_surnombre      ENUM('barrage','tronquer','elargir_cible') NOT NULL DEFAULT 'barrage',
    si_sous_nombre    ENUM('exempts','repecher','reduire_cible') NOT NULL DEFAULT 'exempts',
    PRIMARY KEY (id),
    KEY ix_flux_tournoi (tournoi_id, ordre),
    CONSTRAINT fk_flux_tournoi FOREIGN KEY (tournoi_id)      REFERENCES tournoi (id) ON DELETE CASCADE,
    CONSTRAINT fk_flux_source  FOREIGN KEY (phase_source_id) REFERENCES phase (id) ON DELETE CASCADE,
    CONSTRAINT fk_flux_cible   FOREIGN KEY (phase_cible_id)  REFERENCES phase (id) ON DELETE CASCADE,
    CONSTRAINT fk_flux_regle   FOREIGN KEY (regle_ordre_id)  REFERENCES regle_classement (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Designation manuelle (selecteur = manuel).
CREATE TABLE flux_designation (
    flux_id        INT UNSIGNED NOT NULL,
    inscription_id INT UNSIGNED NOT NULL,
    ordre          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (flux_id, inscription_id),
    CONSTRAINT fk_fd_flux        FOREIGN KEY (flux_id)        REFERENCES flux_qualification (id) ON DELETE CASCADE,
    CONSTRAINT fk_fd_inscription FOREIGN KEY (inscription_id) REFERENCES inscription (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Les objets de competition — §9.3
--
--    En simple, rencontre et partie sont confondues : une rencontre
--    porte une seule partie. En duo et en equipe, une rencontre en porte
--    trois a seize. Le modele ne distingue pas les deux cas : c'est ce
--    qui permet au meme generateur de poules de servir un simple et un
--    championnat par equipes.
--
--    `provenance_a` / `provenance_b` portent les emplacements differes :
--    « vainqueur de la rencontre 12 ». C'est ce qui permet de generer et
--    d'imprimer le tableau ENTIER avant qu'une partie ne soit jouee.
-- ---------------------------------------------------------------------

CREATE TABLE rencontre (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phase_id           INT UNSIGNED NOT NULL,
    groupe_id          INT UNSIGNED NULL,
    tour_id            INT UNSIGNED NULL,
    reference          VARCHAR(48) NOT NULL COMMENT 'id lisible : mbn-T2-01',
    ordre              SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    role               VARCHAR(24) NOT NULL DEFAULT 'poule'
                       COMMENT 'poule | tableau | branche_perdants | grande_finale | petite_finale…',
    camp_a_id          INT UNSIGNED NULL,
    camp_b_id          INT UNSIGNED NULL,
    provenance_a       VARCHAR(64) NULL COMMENT 'vainqueur:REF | perdant:REF | qualifie:CLE | vide',
    provenance_b       VARCHAR(64) NULL,
    systeme_rencontre_id INT UNSIGNED NULL,
    format_partie_id   INT UNSIGNED NULL,
    table_numero       TINYINT UNSIGNED NULL,
    etat               ENUM('planifiee','lancable','en_cours','terminee','non_disputee')
                       NOT NULL DEFAULT 'planifiee',
    score_a            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    score_b            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    debut              DATETIME NULL,
    fin                DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rencontre_ref (phase_id, reference),
    KEY ix_rencontre_etat (phase_id, etat),
    KEY ix_rencontre_groupe (groupe_id, ordre),
    CONSTRAINT fk_rencontre_phase   FOREIGN KEY (phase_id)  REFERENCES phase (id) ON DELETE CASCADE,
    CONSTRAINT fk_rencontre_groupe  FOREIGN KEY (groupe_id) REFERENCES groupe (id) ON DELETE SET NULL,
    CONSTRAINT fk_rencontre_tour    FOREIGN KEY (tour_id)   REFERENCES tour (id) ON DELETE SET NULL,
    CONSTRAINT fk_rencontre_campa   FOREIGN KEY (camp_a_id) REFERENCES camp (id),
    CONSTRAINT fk_rencontre_campb   FOREIGN KEY (camp_b_id) REFERENCES camp (id),
    CONSTRAINT fk_rencontre_systeme FOREIGN KEY (systeme_rencontre_id) REFERENCES systeme_rencontre (id),
    CONSTRAINT fk_rencontre_format  FOREIGN KEY (format_partie_id) REFERENCES format_partie (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RG-82 : `non_disputee` (arret a l'acquis) et `forfait` sont DEUX
-- motifs distincts et ne se comptent pas de la meme maniere. Les
-- confondre est la premiere source de classements faux — c'est pourquoi
-- ils sont separes jusque dans le stockage.
CREATE TABLE partie (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rencontre_id             INT UNSIGNED NOT NULL,
    numero_dans_rencontre    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    camp_a_id                INT UNSIGNED NULL COMMENT 'peut differer de la rencontre (double)',
    camp_b_id                INT UNSIGNED NULL,
    format_partie_id         INT UNSIGNED NULL,
    arbitre_inscription_id   INT UNSIGNED NULL,
    handicap_valeur          SMALLINT NOT NULL DEFAULT 0 COMMENT 'signe',
    handicap_beneficiaire_camp_id INT UNSIGNED NULL,
    handicap_methode         VARCHAR(24) NULL COMMENT 'moyenne | fort_ajuste | somme | dedie',
    score_manches_a          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    score_manches_b          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    motif_fin                ENUM('normal','abandon','forfait','non_disputee','acceleration')
                             NOT NULL DEFAULT 'normal',
    etat                     ENUM('planifiee','en_cours','terminee') NOT NULL DEFAULT 'planifiee',
    saisi_le                 DATETIME NULL COMMENT 'horodatage : departage des saisies concurrentes',
    saisi_par                VARCHAR(64) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_partie (rencontre_id, numero_dans_rencontre),
    KEY ix_partie_motif (motif_fin),
    CONSTRAINT fk_partie_rencontre FOREIGN KEY (rencontre_id) REFERENCES rencontre (id) ON DELETE CASCADE,
    CONSTRAINT fk_partie_campa     FOREIGN KEY (camp_a_id)    REFERENCES camp (id),
    CONSTRAINT fk_partie_campb     FOREIGN KEY (camp_b_id)    REFERENCES camp (id),
    CONSTRAINT fk_partie_format    FOREIGN KEY (format_partie_id) REFERENCES format_partie (id),
    CONSTRAINT fk_partie_arbitre   FOREIGN KEY (arbitre_inscription_id) REFERENCES inscription (id),
    CONSTRAINT fk_partie_benef     FOREIGN KEY (handicap_beneficiaire_camp_id) REFERENCES camp (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RG-73 : le handicap est stocke PAR MANCHE, avec la valeur reellement
-- appliquee. En statique toutes les manches portent la meme valeur —
-- cout de stockage negligeable — et le handicap dynamique (§6.6) devient
-- possible sans aucune refonte.
--
-- `joueurs_camp_a` / `joueurs_camp_b` en JSON servent au relais (§2.5),
-- ou les joueurs d'un camp se succedent au cours de la partie.
CREATE TABLE manche (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    partie_id              INT UNSIGNED NOT NULL,
    numero                 TINYINT UNSIGNED NOT NULL,
    handicap_effectif      SMALLINT NOT NULL DEFAULT 0,
    handicap_beneficiaire_camp_id INT UNSIGNED NULL,
    points_a               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    points_b               SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    joueurs_camp_a         JSON NULL,
    joueurs_camp_b         JSON NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_manche (partie_id, numero),
    CONSTRAINT fk_manche_partie FOREIGN KEY (partie_id) REFERENCES partie (id) ON DELETE CASCADE,
    CONSTRAINT fk_manche_benef  FOREIGN KEY (handicap_beneficiaire_camp_id) REFERENCES camp (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tracabilite du classement — §9.6
--
--    RG-55 : le classement est TOUJOURS derive, jamais saisi. Cette
--    table est un CACHE, invalide a chaque ecriture de resultat sur la
--    phase. Elle n'est jamais source de verite.
--
--    RG-56 : chaque rang stocke le critere qui l'a departage et la
--    valeur de tous les criteres evalues. C'est ce qui permet
--    d'afficher « départagé au ratio de manches (0,714 contre 0,667) »
--    au survol d'un rang — et cela desamorce l'essentiel des
--    contestations.
-- ---------------------------------------------------------------------

CREATE TABLE classement_calcule (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    phase_id              INT UNSIGNED NOT NULL,
    groupe_id             INT UNSIGNED NULL COMMENT 'NULL = classement inter-groupes',
    inscription_id        INT UNSIGNED NOT NULL,
    rang                  SMALLINT UNSIGNED NOT NULL,
    critere_decisif       VARCHAR(32) NULL,
    valeurs_criteres_json JSON NULL,
    calcule_le            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    regle_id              INT UNSIGNED NULL,
    regle_version         SMALLINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_classement (phase_id, groupe_id, inscription_id),
    KEY ix_classement_rang (phase_id, groupe_id, rang),
    CONSTRAINT fk_cc_phase       FOREIGN KEY (phase_id)       REFERENCES phase (id) ON DELETE CASCADE,
    CONSTRAINT fk_cc_groupe      FOREIGN KEY (groupe_id)      REFERENCES groupe (id) ON DELETE CASCADE,
    CONSTRAINT fk_cc_inscription FOREIGN KEY (inscription_id) REFERENCES inscription (id),
    CONSTRAINT fk_cc_regle       FOREIGN KEY (regle_id)       REFERENCES regle_classement (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Classement cumule sur la saison (§5.6b, C.8).
-- RG-61 : le detail par journee est conserve, jamais seulement le total,
-- sinon « les n meilleures journees » devient incalculable.
CREATE TABLE classement_cumule (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    saison_id     SMALLINT UNSIGNED NOT NULL,
    joueur_id     INT UNSIGNED NOT NULL,
    tournoi_id    INT UNSIGNED NOT NULL COMMENT 'la journee',
    points        DECIMAL(7,2) NOT NULL DEFAULT 0,
    detail_json   JSON NULL COMMENT 'participation, victoires, bonus, place, arbitrage',
    fige          TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'cloture_journee',
    PRIMARY KEY (id),
    UNIQUE KEY uk_cumule (saison_id, joueur_id, tournoi_id),
    KEY ix_cumule_saison (saison_id, points),
    CONSTRAINT fk_cumule_saison  FOREIGN KEY (saison_id)  REFERENCES saison (id),
    CONSTRAINT fk_cumule_joueur  FOREIGN KEY (joueur_id)  REFERENCES joueur (id),
    CONSTRAINT fk_cumule_tournoi FOREIGN KEY (tournoi_id) REFERENCES tournoi (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RG-01 : apres passage a l'etat `en_cours`, les modifications restent
-- possibles mais sont journalisees. Sans journal, une contestation sur
-- « la regle a change en cours de soiree » est indefendable.
CREATE TABLE journal_modification (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tournoi_id INT UNSIGNED NOT NULL,
    horodatage DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    auteur     VARCHAR(64) NULL,
    objet      VARCHAR(64) NOT NULL,
    champ      VARCHAR(64) NULL,
    avant      TEXT NULL,
    apres      TEXT NULL,
    motif      VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY ix_journal_tournoi (tournoi_id, horodatage),
    CONSTRAINT fk_journal_tournoi FOREIGN KEY (tournoi_id) REFERENCES tournoi (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
