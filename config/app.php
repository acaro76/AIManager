<?php

return [
    'name' => 'AIManager',
    'version' => '1.0.0',
    'debug' => false,
    'timezone' => 'UTC',
    'database' => dirname(__DIR__) . '/storage/database/aimanager.sqlite',
    // Code — chat read-only sulla cartella.
    'code' => [
        // Provider usato dalla chat Code (nessun selettore in UI: è una scelta di server).
        //   'auto'       → routing normale con fallback (comportamento storico, invariato)
        //   'lmstudio'   → SOLO LM Studio: nessun fallback, quindi nessun contatto cloud
        // Override: variabile d'ambiente AIMANAGER_CODE_PROVIDER_MODE.
        // Un valore mal formato NON ricade su 'auto': produce zero candidati (fail closed).
        'provider_mode' => 'auto',
        // Ciclo agente read-only (Fase 3): il modello sceglie gli strumenti di SOLA LETTURA entro
        // tetti di iterazioni/tempo/budget. false = solo recupero single-shot (comportamento
        // Fase 2). Non abilita scrittura, comandi o processi: gli strumenti restano read-only.
        'agent' => true,
        // Modifica sicura dei file (Fase 4): abilita l'azione `propose_patch`. Anche a true, una
        // proposta NON scrive nulla: l'applicazione avviene SOLO dopo conferma esplicita
        // (POST + CSRF, operation_id + patch_digest, monouso). false = Code resta di sola lettura
        // (comportamento Fase 3). Nessun comando, processo, shell o Git: solo modifica/creazione
        // di file di testo dentro la cartella, con diff, conferma, applicazione atomica e rollback.
        'write' => true,
        // Verifica del codice (Fase 5): abilita l'azione `run_check`, che avvia SOLO profili curati
        // (lint/test/sintassi per PHP/JavaScript/Python) rilevati nella cartella. Nessuna shell,
        // Git, installazione, npm script o Makefile; nessuna scrittura. La capability è attiva dopo
        // il gate reale della Fase 5. Ogni processo ha timeout, output limitato e Stop.
        'verify' => true,
        // Profili abilitati lato server (id curati). null = tutti i curati; una lista li restringe.
        // Il modello può avviare solo profili qui abilitati E rilevati nella cartella.
        'verify_profiles' => null,
        // Comandi locali (Fase 6): abilita l'azione `run_command`, che PROPONE un comando di sola
        // lettura (registro CHIUSO: cat/head/tail/wc/grep/diff/stat/file) con argv validato. Nessun
        // comando parte senza conferma esplicita (POST + CSRF, command_id + digest, monouso).
        // Nessuna shell, interprete generico, package manager, script, rete, Git o mutazione.
        // La capability è attiva dopo il gate reale della Fase 6. HOME/TMP/XDG isolati ed effimeri
        // riducono l'esposizione ma NON sono una sandbox del processo: la difesa è il
        // registro chiuso + la rivalidazione tipizzata dei path. Richiede l'isolamento del process
        // group (pcntl/posix): senza, l'azione non è offerta.
        'commands' => true,
        // Processi persistenti (Fase 7): abilita l'azione `start_process`, che PROPONE l'avvio di un
        // SOLO profilo curato — un server PHP locale `php -S 127.0.0.1:{port} -t {directory}`. Host
        // IMPOSTO a 127.0.0.1 (mai un bind pubblico); il modello sceglie solo porta e directory, mai
        // programma o argv. Nessun processo parte senza conferma esplicita (POST + CSRF, process_id +
        // digest, monouso). Il server esegue codice del workspace e NON è una sandbox del sistema
        // operativo: HOME/TMP/XDG isolati riducono l'esposizione, non la eliminano. Richiede
        // l'isolamento del process group (pcntl/posix). Abilitata dopo migrazione 038 e gate
        // automatico; gate manuale superato e Fase 7 committata in d83f277.
        'processes' => true,

        // Git assistito READ-ONLY (Fase 8): abilita le azioni `git_status` e `git_diff` nel ciclo
        // agente, che espongono al modello lo stato del worktree e i diff (staged/unstaged) tramite
        // GitService. SOLO lettura: nessuno staging, commit, reset, checkout, restore, clean, stash,
        // branch/merge/rebase, fetch/pull/push o rete; nessuna shell Git libera. Git opera solo se il
        // top-level del repository coincide con la root Code; file sensibili e runtime (storage/) sono
        // esclusi (mai nomi né contenuti), con il solo conteggio aggregato. Abilitato dopo i gate
        // completi della Fase 8; ogni mutazione resta soggetta a conferma POST+CSRF monouso.
        'git' => true,
    ],
    'paths' => [
        'root' => dirname(__DIR__),
        'storage' => dirname(__DIR__) . '/storage',
        'plugins' => dirname(__DIR__) . '/plugins',
        'views' => dirname(__DIR__) . '/app/Views',
        'migrations' => dirname(__DIR__) . '/database/migrations',
        'seeds' => dirname(__DIR__) . '/database/seeds',
    ],
];
