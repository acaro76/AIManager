# Contribuire

AIManager accetta modifiche piccole, verificabili e motivate da un uso reale. Prima di proporre una
nuova funzione, descrivi il problema osservato e perché non può essere risolto semplificando il
percorso esistente.

## Sviluppo

- usa PHP 8.5;
- non leggere, copiare o versionare `.env` e `storage/`;
- non inserire credenziali, dati reali o percorsi personali nei test;
- mantieni i confini locali e le conferme di Code;
- aggiungi test proporzionati al rischio.

Esegui prima della proposta:

```bash
php tests/run.php
git diff --check
```

Una modifica dovrebbe avere uno scopo unico, documentare i cambiamenti visibili e non includere
refactoring estranei. Funzioni come esposizione di rete, multiutente, shell generale, push Git,
installer, auto-update o nuova piattaforma richiedono prima una decisione esplicita di prodotto e
sicurezza.

Per vulnerabilità non usare gli issue pubblici: segui [SECURITY.md](SECURITY.md).
