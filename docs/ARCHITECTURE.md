# Le moteur de formules — architecture

Ce document explique comment `src/Formule/` est organisé et **pourquoi**.
Il complète la spécification `Formules_Tournois_TT.md`, qui décrit ce
qu'il faut savoir faire ; celui-ci décrit comment c'est fait.

---

## L'idée directrice

Le document de spécification s'ouvre sur trois observations. La première
commande toute l'architecture :

> Aucune formule n'est monolithique. Chaque formule connue est la
> combinaison d'un petit nombre de décisions indépendantes. Il ne faut
> pas coder « le tournoi type X » mais coder les décisions.

Le moteur applique cela littéralement. **Il n'existe nulle part dans le
code les mots « consolante », « double élimination » ou « critérium » en
tant que branche conditionnelle.** Ce sont des combinaisons de
paramètres et de flux.

Vérification concrète : une consolante est une phase `tableau` alimentée
par un flux `non_qualifies`. Une double élimination est un tableau avec
`defaites_tolerees = 2`. Un critérium est une suite de phases `poules`
reliées par des flux `montants` et `descendants`. Aucun des trois n'a de
code dédié.

---

## Les six couches

```
    Catalogue · Parametres · Expression         règles de configuration
                     ↓
    FormatPartie · Classement · Handicap        règles de jeu
                     ↓
    Structure/    Entite, Plateau, Emplacement, Appariement
                     ↓
    Generation/   les sept briques d'appariement (§3)
                     ↓
    Flux/         qui passe d'une phase à la suivante (C.5)
                     ↓
    Deroulement/  l'orchestrateur
```

Chaque couche ne connaît que celle du dessus. `Generation/` ignore
l'existence des flux ; `Flux/` ignore comment un tableau se dessine.

`Validation/` et `Planification/` sont transverses : la première lit tout
sans rien modifier, la seconde consomme des appariements.

---

## Les pièces qui méritent une explication

### `Emplacement` — pourquoi le tableau existe avant d'être joué

Un côté d'appariement est soit une entité connue, soit une **provenance** :
« vainqueur du match T1-03 », « perdant du match T2-01 ».

C'est ce qui permet de générer et d'imprimer un tableau complet avant
qu'une seule partie ne soit jouée. Sans cela, il faudrait régénérer après
chaque tour, et la feuille de tableau ne pourrait pas être affichée à
l'avance.

C'est aussi ce qui explique pourquoi le système suisse est signalé comme
coûteux au §12.4 : lui seul ne peut pas s'exprimer ainsi. « Le joueur qui
aura deux victoires et le meilleur Buchholz » n'est pas une référence à
un match. `GenerateurSuisse` ne produit donc que le tour 1 et expose
`tourSuivant()`.

### `defaites_tolerees` — quatre formules, une classe

`GenerateurTableau` produit quatre topologies selon un seul paramètre :

| Valeur | Topologie | Nombre de parties |
|---|---|---|
| `1` | Élimination directe | n − 1 |
| `2` | Double élimination | 2n − 2 (+1 avec belle) |
| `infini` | Classement intégral | (n/2) · log₂ n |
| `N ≥ 3` | Vies multiples — routage par flux | — |

Le cas `N ≥ 3` est délibérément laissé au moteur de flux plutôt que codé
comme topologie figée : c'est la recommandation du §3.4, modéliser les
vies comme un compteur porté par l'inscrit.

### `Selecteur` — l'énumération qui doit rester exhaustive

Treize valeurs. Toute formule qu'on ne saurait pas exprimer avec l'une
d'elles exigerait du code spécifique, ce que la matrice de couverture
C.12 interdit. C'est le point de contrôle à surveiller quand une nouvelle
formule apparaît.

### `MoteurFlux` — l'ordre d'application compte

Quatre règles, et leur ordre fait le résultat :

- **RG-31** — une entité n'est prise que par un flux ; le plus petit
  `ordre` gagne.
- **RG-32** — `non_qualifies` est évalué **en dernier**, quel que soit
  son ordre déclaré. C'est ce qui permet d'écrire « les 2 premiers au
  tableau, tout le reste en consolante » sans énumérer les places.
- **RG-33** — au-delà de `capacite_max`, un barrage est **intercalé
  automatiquement**, et les places de ses futurs vainqueurs sont
  réservées dans la phase cible.
- **RG-34** — le placement `croise` écarte les entités de même origine.

### Les places réservées de barrage

Quand un barrage s'intercale, les entités provisoires `phase_barrage#1`,
`#2`… sont ajoutées au plateau de la phase cible. Sans elles, un tableau
de 16 se générerait à 12 entrants et les qualifiés du barrage n'auraient
nulle part où entrer.

Elles portent une référence non numérique, ce qui permet à
`StructureRepository` de les distinguer d'une inscription réelle et de ne
pas les écrire en base.

---

## Ce qui est garanti par les tests

74 tests dans `tests/Formule/`. Les propriétés verrouillées :

- volumes exacts : n−1, 2n−2, (n/2)·log₂n, n(n−1)/2 ;
- les têtes de série 1 et 2 ne peuvent se rencontrer qu'en finale ;
- les exempts vont aux mieux classés ;
- **l'estimation de volume égale exactement le volume généré**, de 16 à
  64 inscrits, barrage intercalaire compris ;
- un joueur n'est jamais lancé sur deux tables ;
- les neuf préréglages se génèrent et sont ouvrables.

Le dernier point de cette liste mérite d'être maintenu : une estimation
fausse une fois et l'organisateur n'ouvrira plus jamais l'écran de
vérification.

---

## Deux points à trancher

### RG-77 est plus sévère que la pratique réelle

La règle telle qu'écrite — avertir si `plafond > points_par_manche / 2` —
se déclenche sur des barèmes documentés au §6.3 :

| Source | Plafond | Manche | RG-77 |
|---|---|---|---|
| Béthune | 18 | 31 | se déclenche |
| Ryedale | 38 | 42 | se déclenche |
| MbN | 8 | 11 | se déclenche |

Ce sont pourtant précisément les formats conçus pour absorber de gros
handicaps. Le code reste fidèle à la spécification et au bon niveau de
sévérité — avertissement, jamais blocage — mais le seuil gagnerait à être
relatif au type de format plutôt qu'absolu.

### Le point ouvert du §7.6 reste ouvert

Le critère `departage_manches_auto` se résout à l'exécution (RG-53), donc
le moteur fonctionne. Mais la question de fond — quel critère remplace
« manches gagnées » entre joueurs de poules différentes en manches
gagnantes — attend toujours sa validation par simulation sur des données
réelles de saison.

---

## Ce qui n'est pas fait

- **L'écran de définition** (§10). Le `Catalogue` sait se rendre sous
  forme de formulaire (`pourEcran()`), les gabarits restent à écrire.
- **La reprise du suisse tour par tour** : `tourSuivant()` existe, son
  branchement sur la persistance non.
- **Le relais** (§2.5) : les colonnes JSON `joueurs_camp_*` sont prévues
  au schéma, rien ne les alimente. C'est délibéré — §12.4 recommande
  d'attendre que le club joue réellement cette formule.
