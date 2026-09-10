# Profila

Applicazione per la pianificazione editoriale di piccole attività, in produzione su **[profila.pro](https://profila.pro)**.

> **Cosa contiene questo repository.** Alcuni estratti del sorgente di Profila, scelti per mostrare come è organizzato il codice. Non ci sono `composer.json`, migrazioni né bootstrap dell'applicazione: il progetto non è eseguibile da qui, è materiale di lettura.

---

## Cosa fa

Profila parte dal sito di un'attività e arriva a un piano editoriale scaricabile. L'applicazione estrae i contenuti del sito, ne ricava un profilo di brand — identità, tono di voce, pubblico, temi ricorrenti — e lo sottopone all'utente, che lo verifica e lo conferma prima che venga generato qualsiasi contenuto. Da lì produce proposte tematiche e un calendario editoriale con formato, canale, testo e indicazione visiva, esportabile in PDF e CSV.

```
  URL del sito
       │
       ▼
     Scan          estrazione contenuti e segnali di categoria
       │
       ▼
     Brief         identità, tono di voce, pubblico, pilastri
       │           ← l'utente verifica e conferma prima di procedere
       ▼
     Idee          proposte tematiche, con memoria di cosa è già uscito
       │
       ▼
     Piano         calendario: formato, canale, testo, indicazione visiva
       │
       ▼
   PDF · CSV
```

## Il mio ruolo

Progetto personale, di cui sono l'unico sviluppatore. Ho definito il prodotto e il suo perimetro, disegnato l'architettura, preso le decisioni tecniche descritte più sotto e ne curo l'esercizio in produzione: deploy, migrazioni, code di lavoro, monitoraggio dei costi.

## Come è organizzato il codice

### `src/Services/Ai/` — il confine verso il modello linguistico

Il resto dell'applicazione non conosce il fornitore. Conosce l'interfaccia [`AiClient`](src/Services/Ai/AiClient.php), che espone metodi di dominio (`generateBrief`, `generateIdeas`, `generatePlanItem`) e restituisce DTO tipizzati anziché array associativi.

Dietro l'interfaccia stanno [`AnthropicClient`](src/Services/Ai/AnthropicClient.php), il client HTTP, e [`FakeAiClient`](src/Services/Ai/FakeAiClient.php), un doppio in memoria. È quello che permette di eseguire l'intera suite senza chiamate di rete e senza costi di API.

Il confine traduce ogni modo di fallire in un'eccezione dedicata ([`Exceptions/`](src/Services/Ai/Exceptions)): timeout, rate limit, JSON non valido, schema non conforme. Chi chiama può reagire in modo diverso a ciascuna. [`JsonExtractor`](src/Services/Ai/JsonExtractor.php) recupera l'oggetto JSON quando la risposta contiene testo aggiuntivo intorno ad esso.

Ogni chiamata scrive una riga con token in ingresso, token in uscita e costo calcolato ([`AiUsageLogger`](src/Services/Ai/AiUsageLogger.php), [`AiCostCalculator`](src/Services/Ai/AiCostCalculator.php)), così il consumo è attribuibile per utente e per tipo di generazione.

### `src/Models/Brand.php` e `src/Policies/` — isolamento fra utenti

L'accesso ai dati passa da tre meccanismi distinti:

1. Un **global scope** su [`Brand`](src/Models/Brand.php) che, quando è presente un utente autenticato, aggiunge `where('user_id', auth()->id())` alle query.
2. Una **policy** per risorsa ([`BrandPolicy`](src/Policies/BrandPolicy.php)) con un metodo per azione, applicata a ogni punto di ingresso.
3. L'**accesso via relazione** — `auth()->user()->brands()` invece di `Brand::find()`.

Sono livelli indipendenti: il primo riduce la superficie per errore di distrazione, il secondo autorizza esplicitamente, il terzo rende l'accesso corretto quello più naturale da scrivere.

### `src/Services/Discovery/` — ricerca di attività simili

Lo stesso schema applicato una seconda volta: [`BraveSearchClient`](src/Services/Discovery/BraveSearchClient.php) è un'interfaccia con implementazione reale e doppio.

Sopra ci sono filtri deterministici, scritti in PHP e non delegati al modello: [`BlocklistFilter`](src/Services/Discovery/BlocklistFilter.php) scarta aggregatori e directory, [`ConfidenceScorer`](src/Services/Discovery/ConfidenceScorer.php) assegna un punteggio ai candidati, [`PrudentLanguageGate`](src/Services/Discovery/PrudentLanguageGate.php) intercetta le espressioni presenti in una lista prima che il testo venga usato.

### `tests/`

Esempi di test su autorizzazioni e sul confine verso il modello. [`Brand/IsolationTest`](tests/Feature/Brand/IsolationTest.php) e [`BrandSource/IsolationTest`](tests/Feature/BrandSource/IsolationTest.php) verificano che un utente non raggiunga le risorse di un altro su ciascuna rotta; [`AnthropicClientContractTest`](tests/Feature/Ai/AnthropicClientContractTest.php) verifica la forma della richiesta HTTP e la mappatura dei codici di errore sulle eccezioni.

---

## Tre scelte e il loro compromesso

**Un accesso cross-tenant risponde 404, non 403.** Un 403 conferma che la risorsa esiste. Poiché il global scope la nasconde già a monte, una risorsa altrui risulta indistinguibile da una inesistente. Si perde un po' di leggibilità in fase di debug, si evita di rivelare l'esistenza di dati altrui.

**Gli enum sono enum PHP su colonna `VARCHAR(30)`, non `ENUM` SQL.** Aggiungere un valore a un enum SQL richiede un `ALTER TABLE` su tabella popolata. Tenendo il vincolo nel codice si rinuncia al controllo a livello di database, ma lo schema evolve senza intervento sulla tabella e i valori ammessi restano versionati insieme al resto.

**La lunghezza del testo si misura con `mb_strlen($testo, 'UTF-8')`.** Il dominio è italiano e le lettere accentate occupano due byte: con `strlen` un limite di 150 caratteri ne accetta circa 130 e tronca a metà parola. La regola è in [`MultibyteMax`](src/Rules/MultibyteMax.php).

---

## Test

La suite completa del progetto conta **2.138 test e 8.536 asserzioni**, eseguiti su SQLite in memoria (rilevazione del 10 settembre 2026). Il dato si riferisce al progetto intero: questo repository contiene solo estratti e non include l'ambiente necessario a eseguirla, quindi il valore è dichiarato e non verificabile da qui.

## Stack

PHP 8.3 · Laravel 11 · Livewire 3 con Volt · Tailwind CSS 4 · MariaDB in produzione, SQLite in memoria nei test · Pest 4 · Anthropic Claude

## Cosa non è incluso

I template di prompt e i profili di settore non fanno parte degli estratti, e la lista completa delle espressioni filtrate da `PrudentLanguageGate` è ridotta a due voci di esempio. Restano fuori anche l'impalcatura dell'applicazione, le migrazioni, le viste e gli script di deploy.

---

© 2026. Tutti i diritti riservati. Codice pubblicato a solo scopo di consultazione.
