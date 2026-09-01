# Module Tournois — Royal Mickey Club Falisolle

Gestion des tournois « Mickey By Night » : inscriptions, poules,
encodage des matchs, classements, barrages, tableaux finaux et barème
de points.

Remplace le jeu de classeurs Excel/VBA (`MbN1` à `MbN4.xlsm`,
`Classements_Presences.xlsm`, `Fichier Principal vierge.xlsm`).

Référence fonctionnelle : `Conception_Tournois_MbN.docx`.

## Pile technique

PHP 8.2 · MariaDB 10.11 · Nginx · Alpine.js · mPDF · PHPUnit 10

## Installation

```bash
cp config/config.local.php.exemple config/config.local.php
# renseigner les identifiants MariaDB, puis :
composer install
```

Ouvrir ensuite l'URL du module : la page d'accueil vérifie que
l'environnement est complet.

## Organisation

| Dossier | Contenu |
|---|---|
| `config/` | configuration ; `config.local.php` n'est jamais versionné |
| `db/` | schéma SQL : `01_reference.sql` puis `02_tournoi.sql` |
| `docs/` | `ARCHITECTURE.md` — le moteur de formules expliqué |
| `src/Domain/` | règles métier MbN historiques — ni SQL, ni HTML |
| `src/Formule/` | moteur générique de formules de tournoi (voir ci-dessous) |
| `src/Repository/` | accès MariaDB via PDO |
| `src/Http/` | contrôleurs, un par page |
| `templates/` | gabarits partagés entre affichage web, PDF et image |
| `tools/` | `demo.php` — déroule un tournoi en console |
| `assets/` | seuls fichiers réellement publics |
| `tests/` | tests PHPUnit des règles métier |

Les autres dossiers sont interdits d'accès par la configuration Nginx.

## Le moteur de formules

`src/Formule/` implémente la spécification `Formules_Tournois_TT.md` :
toute formule de tournoi s'y exprime par des paramètres et des flux,
sans code spécifique.

| Sous-dossier | Rôle |
|---|---|
| `Catalogue`, `Parametres`, `Expression` | les paramètres et leur héritage sur cinq niveaux |
| `FormatPartie`, `Classement/`, `Handicap/`, `Rencontre/` | les règles de jeu et de départage |
| `Structure/` | entités, plateaux, appariements |
| `Generation/` | les sept briques d'appariement : poules, tableau, croisé, suisse, échelle, barrage |
| `Flux/` | qui passe d'une phase à la suivante |
| `Deroulement/` | l'orchestrateur et les neuf préréglages |
| `Validation/` | contrôles avant ouverture |
| `Planification/` | affectation des tables |

Essai rapide :

```bash
php tools/demo.php liste
php tools/demo.php mbn_classique 24
```

## Installation de la base

```bash
mysql mickey_tournois_dev < db/01_reference.sql
mysql mickey_tournois_dev < db/02_tournoi.sql
```

L'ordre compte : le second fichier référence les tables du premier.

## Tests

```bash
./vendor/bin/phpunit
```

## Environnements

Développement et production ne diffèrent que par `config.local.php` :

| | `base_url` | `debug` | base de données |
|---|---|---|---|
| Dév | `/gestion_tournois_dev` | `true` | `mickey_tournois_dev` |
| Prod | `/gestion_tournois` | `false` | `mickey_tournois` |
