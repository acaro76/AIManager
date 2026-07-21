<?php

declare(strict_types=1);

namespace App\Core\Code;

/**
 * Code — Fase 8 / base Git read-only. Errore ATTESO del sottosistema Git (root non è un
 * repository, eseguibile git non disponibile in bin fidata, comando fallito o interrotto).
 *
 * Come CodeWorkspaceException per il filesystem, distingue le condizioni previste dai bug:
 * chi orchestra Git cattura SOLO questa. Non è un canale per l'output di git, che resta un DATO
 * non fidato nei DTO, mai un'eccezione.
 */
final class GitException extends \RuntimeException
{
}
