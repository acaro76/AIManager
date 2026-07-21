<?php

declare(strict_types=1);

namespace App\Core\ContextEngine;

/**
 * Contesto che porta un system prompt DEDICATO, da usare al posto di quello costruito dal
 * provider a partire dal progetto LLM.
 *
 * Serve alle superfici che non sono chat LLM — oggi Code (`App\Core\Code\CodeContext`) — e il
 * supporto nei provider è ADDITIVO: solo un contesto che implementa questa interfaccia ottiene
 * il proprio system prompt. I contesti LLM esistenti (`Context`) non la implementano e il loro
 * comportamento resta identico.
 */
interface SystemPromptContextInterface extends ContextInterface
{
    /** Il system prompt completo della superficie, già pronto (nessun ramo "progetto LLM"). */
    public function systemPrompt(): string;
}
