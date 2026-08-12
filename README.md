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

## Tests

```bash
./vendor/bin/phpunit
```

## Organisation

| Dossier | Contenu |
|---|---|
| `config/` | configuration ; `config.local.php` n'est jamais versionné |
| `src/Domain/` | règles métier pures — ni SQL, ni HTML, testables seules |
| `src/Repository/` | accès MariaDB via PDO |
| `src/Http/` | contrôleurs, un par page |
| `templates/` | gabarits partagés entre affichage web, PDF et image |
| `assets/` | seuls fichiers réellement publics |
| `tests/` | tests PHPUnit des règles métier |

Les autres dossiers sont interdits d'accès par la configuration Nginx.

## Environnements

Développement et production ne diffèrent que par `config.local.php` :

| | `base_url` | `debug` | base de données |
|---|---|---|---|
| Dév | `/gestion_tournois_dev` | `true` | `mickey_tournois_dev` |
| Prod | `/gestion_tournois` | `false` | `mickey_tournois` |
