# Profila

**Piattaforma di pianificazione editoriale per piccole attività italiane.**
In produzione su **[profila.pro](https://profila.pro)**.

> ### Cosa contiene questo repository
> Sono **estratti** dal sorgente di Profila: una selezione di file scelti per mostrare le scelte di architettura.
> **Non è un progetto eseguibile** — non ci sono `composer.json`, migrazioni né bootstrap dell'applicazione. È materiale di lettura.
> Il sorgente completo è disponibile su richiesta.

---

## Il problema

Un B&B, una trattoria, un'estetista non hanno un social media manager. Hanno un sito, una pagina Instagram aggiornata a singhiozzo e nessuna idea di cosa pubblicare lunedì prossimo.

Profila parte da un URL e arriva a un piano editoriale scaricabile:

```
  URL del sito
       │
       ▼
  ┌─────────────┐   estrazione contenuti + segnali di categoria
  │    Scan     │   (no browser headless, no servizi a pagamento)
  └─────────────┘
       │
       ▼
  ┌─────────────┐   identità, tono di voce, pubblico, pilastri
  │    Brief    │   ← confermato dall'utente prima di procedere
  └─────────────┘
       │
       ▼
  ┌─────────────┐   proposte tematiche, con memoria di cosa è già uscito
  │    Idee     │
  └─────────────┘
       │
       ▼
  ┌─────────────┐   calendario con formato, canale, testo, indicazione visual
  │    Piano    │
  └─────────────┘
       │
       ▼
    PDF · CSV
```

## Numeri

| | |
|---|---|
| Codice applicativo | ~34.000 righe |
| Test | **2.138 test, 8.536 asserzioni**, suite verde |
| Rapporto test/codice | ~1,4 : 1 |
| Classi di servizio | 107 |
| In produzione dal | maggio 2026 |

---

## Cosa trovi in questo estratto

### 1. `src/Services/Ai/` — l'astrazione sul modello linguistico

Il resto dell'applicazione non sa che dietro c'è Anthropic. Conosce solo l'interfaccia [`AiClient`](src/Services/Ai/AiClient.php), che espone metodi di dominio (`generateBrief`, `generateIdeas`, `generatePlanItem`) e restituisce **DTO tipizzati**, mai array associativi.

Dietro l'interfaccia ci sono due implementazioni: [`AnthropicClient`](src/Services/Ai/AnthropicClient.php), il client HTTP reale, e [`FakeAiClient`](src/Services/Ai/FakeAiClient.php), il doppio in memoria. È la ragione per cui 2.138 test girano senza spendere un centesimo di API e senza dipendere dalla rete.

Il confine HTTP traduce ogni modo di fallire in un'**eccezione tipizzata** ([`Exceptions/`](src/Services/Ai/Exceptions)): timeout, rate limit, JSON malformato, schema non conforme. Chi chiama decide cosa fare di ciascuna, invece di ricevere un `false` indistinto. [`JsonExtractor`](src/Services/Ai/JsonExtractor.php) recupera il JSON quando il modello lo annega nella prosa.

Ogni chiamata scrive una riga di consumo con token in ingresso, in uscita e costo calcolato ([`AiUsageLogger`](src/Services/Ai/AiUsageLogger.php), [`AiCostCalculator`](src/Services/Ai/AiCostCalculator.php)). Senza questo, in un prodotto a costo variabile per utente si naviga alla cieca.

### 2. `src/Models/Brand.php` + `src/Policies/` — isolamento multi-tenant

Ogni risorsa appartiene a un utente e nessun utente deve poter vedere quelle di un altro. Tre livelli indipendenti, perché uno solo prima o poi si buca:

1. **Global Scope** su [`Brand`](src/Models/Brand.php) — filtra `WHERE user_id = auth()->id()` su ogni query, di default.
2. **Policy** ([`BrandPolicy`](src/Policies/BrandPolicy.php)) — autorizzazione esplicita a ogni azione, difesa in profondità.
3. **Accesso via relazione** — `auth()->user()->brands()` , mai `Brand::find()`.

### 3. `src/Services/Discovery/` — ricerca di attività simili

Stesso pattern applicato una seconda volta: [`BraveSearchClient`](src/Services/Discovery/BraveSearchClient.php) è un'interfaccia con implementazione reale e doppio.

Sopra ci sono tre filtri deterministici che non delegano il giudizio al modello: [`BlocklistFilter`](src/Services/Discovery/BlocklistFilter.php) scarta aggregatori e directory, [`ConfidenceScorer`](src/Services/Discovery/ConfidenceScorer.php) attribuisce un punteggio a ogni candidato, [`PrudentLanguageGate`](src/Services/Discovery/PrudentLanguageGate.php) blocca le formulazioni troppo assertive prima che arrivino all'utente.

### 4. `tests/` — la parte su cui il progetto si regge

[`Brand/IsolationTest`](tests/Feature/Brand/IsolationTest.php) e [`BrandSource/IsolationTest`](tests/Feature/BrandSource/IsolationTest.php) sono i test cross-tenant: l'utente B tenta di leggere, modificare e cancellare le risorse dell'utente A, su ogni rotta.

---

## Alcune decisioni, con il loro compromesso

**404 e non 403 sull'accesso cross-tenant.** Un 403 conferma che la risorsa esiste. Il Global Scope nasconde l'esistenza, quindi una risorsa altrui è indistinguibile da una inesistente. Costa un po' di chiarezza in debug, elimina una fuga di informazione.

**Enum PHP con colonna `VARCHAR(30)`, mai `ENUM` SQL.** Aggiungere un valore a un enum SQL è un `ALTER TABLE` su tabella piena. Con `VARCHAR` più cast Eloquent il vincolo vive nel codice, dove è versionato e testato. Si rinuncia al controllo a livello di database, si guadagna la possibilità di evolvere lo schema senza fermi.

**`mb_strlen($testo, 'UTF-8')` ovunque, mai `strlen`.** Il dominio è italiano: `è`, `à`, `ò` occupano due byte. Con `strlen` un limite di 150 caratteri ne accetta 130 e taglia le caption a metà parola. Vedi [`MultibyteMax`](src/Rules/MultibyteMax.php).

**Nessuna generazione di immagini, nessuna pubblicazione automatica.** Il suggerimento visivo resta testuale e i contenuti si esportano, non si pubblicano. Restringe il prodotto e lo rende difendibile: niente credenziali social da custodire, niente post inviati per errore a nome di un cliente.

**Un gate deterministico prima dell'output del modello.** [`PrudentLanguageGate`](src/Services/Discovery/PrudentLanguageGate.php) è una lista di frasi vietate applicata in PHP, non un'istruzione al modello. Le istruzioni si possono ignorare; un filtro no.

---

## Stack

PHP 8.3 · Laravel 11 · Livewire 3 + Volt · Tailwind CSS 4 · MySQL 8 in sviluppo, MariaDB in produzione, SQLite in memoria nei test · Pest 4 · Anthropic Claude

---

## Cosa non è incluso

I **template di prompt** e i profili verticali di settore non fanno parte di questo estratto: sono la parte proprietaria del prodotto. Restano fuori anche l'impalcatura dell'applicazione, le migrazioni, le viste e gli script di deploy.

---

## Il prodotto

**[profila.pro](https://profila.pro)** — account dimostrativo su richiesta.

---

© 2026. Tutti i diritti riservati. Il codice è pubblicato a solo scopo di consultazione.
