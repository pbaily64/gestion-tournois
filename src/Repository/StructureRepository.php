<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RMCF\Tournois\Formule\Deroulement\DefinitionTournoi;
use RMCF\Tournois\Formule\Deroulement\TournoiGenere;
use RMCF\Tournois\Formule\Structure\Appariement;
use RMCF\Tournois\Formule\Structure\Emplacement;
use RuntimeException;

/**
 * Ecriture de la structure generee : phases, groupes, tours, rencontres.
 *
 * LA REGLE QUI GOUVERNE CE FICHIER : on n'ecrase jamais un resultat.
 *
 * Regenerer une phase est une operation frequente et normale — a chaque
 * cloture de la phase precedente, le tableau se remplit un peu plus. Si
 * la regeneration effacait puis recreait les rencontres, elle detruirait
 * les scores deja saisis. Le repository procede donc par
 * RAPPROCHEMENT : chaque rencontre generee est identifiee par sa
 * reference stable (`mbn-T2-01`), et une rencontre existante n'est mise
 * a jour que sur ses champs de structure — jamais sur son score, jamais
 * sur son etat s'il est deja `terminee`.
 *
 * C'est la meme prudence que celle du cote Excel avec la cloture de
 * journee, mais obtenue par construction plutot que par discipline.
 */
final class StructureRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Ecrit la structure generee sous un tournoi existant.
     *
     * @return array{phases:int,rencontres_creees:int,rencontres_majes:int,rencontres_protegees:int}
     */
    public function enregistrer(int $tournoiId, DefinitionTournoi $definition, TournoiGenere $genere): array
    {
        $bilan = [
            'phases'               => 0,
            'rencontres_creees'    => 0,
            'rencontres_majes'     => 0,
            'rencontres_protegees' => 0,
        ];

        $this->pdo->beginTransaction();

        try {
            foreach ($genere->phases as $code => $structure) {
                $definitionPhase = $definition->phase($code);

                $phaseId = $this->phase(
                    $tournoiId,
                    $code,
                    $definitionPhase?->libelle() ?? $code,
                    $structure->type,
                    $definitionPhase?->ordre ?? ($bilan['phases'] + 1),
                    $definitionPhase?->conditionActivation,
                    $structure->meta,
                );

                $bilan['phases']++;

                $groupes = [];

                foreach ($structure->groupes as $libelle => $membres) {
                    $groupes[$libelle] = $this->groupe($phaseId, $libelle, count($groupes) + 1);
                    $this->membresGroupe($groupes[$libelle], $membres);
                }

                $tours = [];

                foreach ($structure->tours as $numero => $libelle) {
                    $tours[$numero] = $this->tour($phaseId, $numero, $libelle);
                }

                foreach ($structure->appariements as $appariement) {
                    $etat = $this->rencontre(
                        $phaseId,
                        $appariement,
                        $groupes[$appariement->groupe] ?? null,
                        $tours[$appariement->tour] ?? null,
                    );

                    $bilan[$etat]++;
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw new RuntimeException(
                'Enregistrement de la structure impossible : ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $bilan;
    }

    // -----------------------------------------------------------------
    // Phases, groupes, tours
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $meta */
    private function phase(
        int $tournoiId,
        string $code,
        string $libelle,
        string $type,
        int $ordre,
        ?string $condition,
        array $meta,
    ): int {
        $st = $this->pdo->prepare(
            'SELECT id FROM ' . table('phase') . ' WHERE tournoi_id = ? AND code = ?'
        );
        $st->execute([$tournoiId, $code]);
        $id = $st->fetchColumn();

        $json = json_encode($meta, JSON_UNESCAPED_UNICODE) ?: null;

        if ($id !== false) {
            $maj = $this->pdo->prepare(
                'UPDATE ' . table('phase')
                . ' SET libelle = ?, type_phase = ?, ordre = ?, condition_activation = ?,'
                . '     parametres_json = ?'
                . ' WHERE id = ?'
            );
            $maj->execute([$libelle, $type, $ordre, $condition ?: null, $json, (int) $id]);

            return (int) $id;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO ' . table('phase')
            . ' (tournoi_id, code, ordre, libelle, type_phase, condition_activation, parametres_json)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([$tournoiId, $code, $ordre, $libelle, $type, $condition ?: null, $json]);

        return (int) $this->pdo->lastInsertId();
    }

    private function groupe(int $phaseId, string $code, int $ordre): int
    {
        $st = $this->pdo->prepare(
            'SELECT id FROM ' . table('groupe') . ' WHERE phase_id = ? AND code = ?'
        );
        $st->execute([$phaseId, $code]);
        $id = $st->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO ' . table('groupe') . ' (phase_id, code, libelle, ordre) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$phaseId, $code, $code, $ordre]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<string> $membres references d'inscription */
    private function membresGroupe(int $groupeId, array $membres): void
    {
        $ins = $this->pdo->prepare(
            'INSERT INTO ' . table('groupe_membre') . ' (groupe_id, inscription_id, position)'
            . ' VALUES (?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE position = VALUES(position)'
        );

        $position = 1;

        foreach ($membres as $ref) {
            // Les places reservees d'un barrage non joue portent une
            // reference symbolique (« tableau_barrage#1 ») : elles n'ont
            // pas d'inscription et ne s'ecrivent pas.
            if (! ctype_digit($ref)) {
                $position++;
                continue;
            }

            $ins->execute([$groupeId, (int) $ref, $position++]);
        }
    }

    private function tour(int $phaseId, int $numero, string $libelle): int
    {
        $st = $this->pdo->prepare(
            'SELECT id FROM ' . table('tour') . ' WHERE phase_id = ? AND numero = ?'
        );
        $st->execute([$phaseId, $numero]);
        $id = $st->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO ' . table('tour') . ' (phase_id, numero, libelle) VALUES (?, ?, ?)'
        );
        $ins->execute([$phaseId, $numero, $libelle]);

        return (int) $this->pdo->lastInsertId();
    }

    // -----------------------------------------------------------------
    // Rencontres
    // -----------------------------------------------------------------

    /**
     * Cree ou met a jour une rencontre, sans jamais toucher a un score.
     *
     * @return 'rencontres_creees'|'rencontres_majes'|'rencontres_protegees'
     */
    private function rencontre(
        int $phaseId,
        Appariement $appariement,
        ?int $groupeId,
        ?int $tourId,
    ): string {
        $st = $this->pdo->prepare(
            'SELECT id, etat FROM ' . table('rencontre') . ' WHERE phase_id = ? AND reference = ?'
        );
        $st->execute([$phaseId, $appariement->id]);
        $existante = $st->fetch(PDO::FETCH_ASSOC);

        $campA = $this->campDe($appariement->a);
        $campB = $this->campDe($appariement->b);

        $etat = match (true) {
            $appariement->estExempt()   => 'non_disputee',
            $appariement->estLancable() => 'lancable',
            default                     => 'planifiee',
        };

        if ($existante !== false) {
            // Une rencontre jouee est intouchable : la regeneration ne
            // doit jamais faire disparaitre un resultat saisi.
            if (in_array($existante['etat'], ['terminee', 'en_cours'], true)) {
                return 'rencontres_protegees';
            }

            $maj = $this->pdo->prepare(
                'UPDATE ' . table('rencontre')
                . ' SET groupe_id = ?, tour_id = ?, ordre = ?, role = ?,'
                . '     camp_a_id = ?, camp_b_id = ?, provenance_a = ?, provenance_b = ?, etat = ?'
                . ' WHERE id = ?'
            );

            $maj->execute([
                $groupeId,
                $tourId,
                $appariement->ordre,
                $appariement->role,
                $campA,
                $campB,
                $this->provenance($appariement->a),
                $this->provenance($appariement->b),
                $etat,
                (int) $existante['id'],
            ]);

            return 'rencontres_majes';
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO ' . table('rencontre')
            . ' (phase_id, groupe_id, tour_id, reference, ordre, role,'
            . '  camp_a_id, camp_b_id, provenance_a, provenance_b, etat)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $ins->execute([
            $phaseId,
            $groupeId,
            $tourId,
            $appariement->id,
            $appariement->ordre,
            $appariement->role,
            $campA,
            $campB,
            $this->provenance($appariement->a),
            $this->provenance($appariement->b),
            $etat,
        ]);

        return 'rencontres_creees';
    }

    /**
     * Le camp d'un emplacement, s'il est connu.
     *
     * En simple, `camp` et `inscription` sont en correspondance
     * un-a-un : on retrouve le camp par son membre. En double et en
     * equipe, le camp est cree a l'inscription et la reference porte
     * deja son identifiant.
     */
    private function campDe(Emplacement $emplacement): ?int
    {
        if (! $emplacement->estConnu() || $emplacement->reference === null) {
            return null;
        }

        if (! ctype_digit($emplacement->reference)) {
            return null; // place reservee, pas encore attribuee
        }

        $st = $this->pdo->prepare(
            'SELECT camp_id FROM ' . table('camp_membre') . ' WHERE inscription_id = ? LIMIT 1'
        );
        $st->execute([(int) $emplacement->reference]);
        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * L'emplacement differe, serialise pour la colonne `provenance_*`.
     *
     * C'est ce qui permet d'imprimer un tableau complet des l'ouverture :
     * « vainqueur:mbn-T1-03 » se lit sur la feuille comme « vainqueur du
     * match 3 » et se resout mecaniquement des que le match est saisi.
     */
    private function provenance(Emplacement $emplacement): ?string
    {
        return match ($emplacement->nature) {
            Emplacement::ENTITE => ctype_digit((string) $emplacement->reference)
                ? null
                : 'qualifie:' . $emplacement->reference,
            Emplacement::VIDE   => 'vide',
            default             => $emplacement->nature . ':' . $emplacement->reference,
        };
    }
}
