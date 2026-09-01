<?php

declare(strict_types=1);

/**
 * Demonstration : deroule un tournoi complet en console.
 *
 *     php tools/demo.php [prereglage] [nb_inscrits]
 *     php tools/demo.php mbn_classique 24
 *
 * Trois usages :
 *
 *   1. VERIFIER l'installation — si ce script tourne, le moteur est
 *      operationnel de bout en bout ;
 *   2. EXPLORER une configuration avant de l'ouvrir : combien de
 *      parties, combien de temps, quels avertissements ;
 *   3. DOCUMENTER — lire la sortie apprend plus vite qu'un manuel ce
 *      que fait chaque prereglage.
 *
 * Aucun acces a la base : tout se joue en memoire.
 */

require __DIR__ . '/../vendor/autoload.php';

use RMCF\Tournois\Formule\Deroulement\MoteurTournoi;
use RMCF\Tournois\Formule\Deroulement\Prereglages;
use RMCF\Tournois\Formule\Flux\ResultatsEnMemoire;
use RMCF\Tournois\Formule\Structure\Appariement;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\Plateau;
use RMCF\Tournois\Formule\Validation\Anomalie;
use RMCF\Tournois\Formule\Validation\Validateur;

$code       = $argv[1] ?? 'mbn_classique';
$nbInscrits = (int) ($argv[2] ?? 24);

if ($code === '--liste' || $code === 'liste') {
    echo "Préréglages disponibles :\n\n";

    foreach (Prereglages::catalogue() as $cle => $libelle) {
        printf("  %-22s %s\n", $cle, $libelle);
    }

    exit(0);
}

$definition = Prereglages::parCode($code);

echo str_repeat('=', 70), "\n";
printf("  %s — %d inscrits\n", $definition->libelle(), $nbInscrits);
echo str_repeat('=', 70), "\n\n";

// --- 1. Validation avant ouverture (§10.3) ---------------------------

$validateur = new Validateur();
$anomalies  = $validateur->valider($definition, $nbInscrits);

echo "VÉRIFICATION AVANT OUVERTURE\n\n";
echo $validateur->rapport($anomalies), "\n\n";

if (! $validateur->ouvrable($anomalies)) {
    exit(1);
}

// --- 2. Plateau des inscrits ------------------------------------------

$entites = [];

for ($i = 1; $i <= $nbInscrits; $i++) {
    $entites[] = new Entite(
        ref: 'J' . $i,
        libelle: 'Joueur ' . $i,
        rang: $i,
        classementGele: max(0, 17 - intdiv($i - 1, 2)),
    );
}

$inscrits = new Plateau($entites);

// --- 3. Génération de la phase 1 --------------------------------------

$resultats = new ResultatsEnMemoire($entites);
$moteur    = new MoteurTournoi($resultats, graine: 2026);
$genere    = $moteur->generer($definition, $inscrits);

echo str_repeat('-', 70), "\n";
echo "STRUCTURE AVANT LE PREMIER MATCH\n\n";
echo $genere->resume(), "\n\n";

foreach ($genere->phases as $phase) {
    if ($phase->groupes === [] || $phase->type !== 'poules') {
        continue;
    }

    foreach ($phase->groupes as $libelle => $membres) {
        printf("  Poule %s : %s\n", $libelle, implode(', ', $membres));
    }

    echo "\n";
}

// --- 4. On simule la clôture des poules -------------------------------

$premiere = array_key_first($genere->phases);
$phaseUn  = $genere->phases[$premiere];

if ($phaseUn->type === 'poules') {
    foreach ($phaseUn->groupes as $libelle => $membres) {
        // Classement fictif : l'ordre de placement fait foi.
        $resultats->classer($premiere, $libelle, $membres);
    }

    $genere = $moteur->generer($definition, $inscrits);

    echo str_repeat('-', 70), "\n";
    echo "STRUCTURE APRÈS CLÔTURE DES POULES\n\n";
    echo $genere->resume(), "\n\n";
}

// --- 5. La file d'attente de la table de marque ------------------------

$lancables = $genere->lancables();

echo str_repeat('-', 70), "\n";
printf("PARTIES IMMÉDIATEMENT LANÇABLES : %d sur %d\n\n", count($lancables), $genere->nombreParties());

foreach (array_slice($lancables, 0, 12) as $appariement) {
    printf(
        "  %-18s %-26s %s\n",
        $appariement->id,
        $appariement->afficher(),
        $appariement->enjeu ?? ''
    );
}

if (count($lancables) > 12) {
    printf("  … et %d autre(s)\n", count($lancables) - 12);
}

// --- 6. Les parties différées ------------------------------------------

$differees = array_values(array_filter(
    $genere->appariements(),
    static fn (Appariement $a): bool => ! $a->estLancable() && ! $a->estExempt()
));

if ($differees !== []) {
    echo "\n", str_repeat('-', 70), "\n";
    printf("PARTIES EN ATTENTE D'UN RÉSULTAT : %d\n\n", count($differees));

    foreach (array_slice($differees, 0, 6) as $appariement) {
        printf("  %-18s %s\n", $appariement->id, $appariement->afficher());
    }
}

echo "\n";
