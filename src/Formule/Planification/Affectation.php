<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Planification;

use RMCF\Tournois\Formule\Structure\Appariement;

/**
 * Une partie lancee : quelle partie, sur quelle table, a quel moment.
 *
 * L'alerte porte le non-respect du repos minimum. Elle n'empeche pas le
 * lancement — elle s'imprime sur la feuille de table et sert au
 * juge-arbitre, qui reste seul juge de l'opportunite.
 */
final class Affectation
{
    public function __construct(
        public readonly Appariement $appariement,
        public readonly int $table,
        public readonly int $minute = 0,
        public readonly ?string $alerte = null,
    ) {
    }

    public function afficher(): string
    {
        return sprintf(
            'Table %d — %s%s',
            $this->table,
            $this->appariement->afficher(),
            $this->alerte !== null ? ' (⚠ ' . $this->alerte . ')' : ''
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'appariement' => $this->appariement->id,
            'table'       => $this->table,
            'minute'      => $this->minute,
            'alerte'      => $this->alerte,
        ];
    }
}
