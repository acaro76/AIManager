<?php

declare(strict_types=1);

namespace App\Core\Code;

/** Impedisce a Code di operare su AIManager o su un albero che lo interseca. */
final class CodeSelfProtection
{
    private readonly string $applicationRoot;

    public function __construct(?string $applicationRoot = null)
    {
        $root = $applicationRoot ?? dirname(__DIR__, 3);
        $canonical = realpath($root);
        if ($canonical === false || !is_dir($canonical)) {
            throw new \RuntimeException('Root applicativa non disponibile per la protezione Code.');
        }
        $this->applicationRoot = rtrim($canonical, DIRECTORY_SEPARATOR);
    }

    public function assertSeparate(string $workspaceRoot): void
    {
        $canonical = realpath($workspaceRoot);
        if ($canonical === false || !is_dir($canonical)) {
            throw new CodeWorkspaceException('La cartella autorizzata non è più valida.');
        }
        $canonical = rtrim($canonical, DIRECTORY_SEPARATOR);

        if ($canonical === $this->applicationRoot
            || str_starts_with($canonical . DIRECTORY_SEPARATOR, $this->applicationRoot . DIRECTORY_SEPARATOR)
            || str_starts_with($this->applicationRoot . DIRECTORY_SEPARATOR, $canonical . DIRECTORY_SEPARATOR)) {
            throw new CodeWorkspaceException('AIManager non può operare su sé stesso tramite Code.');
        }
    }
}
