<?php

declare(strict_types=1);

namespace App\Core\Code;

use RuntimeException;

/**
 * Errore ATTESO del Reparto Code: workspace o percorso non valido (root fuori confine,
 * revocata, eliminata, sensibile, progetto non idoneo…). Serve a distinguerlo dai bug o
 * dagli errori DB/interni, che NON devono essere silenziati né mostrati come "cartella
 * non valida".
 */
final class CodeWorkspaceException extends RuntimeException
{
}
