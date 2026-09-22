# lexware-office-3cx

**Caller ID & contact search from Lexware Office (formerly lexoffice) in your 3CX phone system.**

🇬🇧 [English](#-english) · 🇩🇪 [Deutsch](#-deutsch)

A 3CX CRM template plus a small self-hosted connector that shows the name and
company of incoming callers — resolved live from your Lexware Office contacts.

---

# 🇬🇧 English

## What it does

- Resolves incoming callers to **name and company** from your Lexware Office
  contacts — in the 3CX web client, the apps and missed-call emails. Desk phones
  show the name once the contact is in the 3CX phonebook (see
  [Desk phones](#desk-phones)).
- Adds **contact search** in the 3CX web client ("add contact from CRM").
- **Screen pop**: opens the contact in Lexware Office with one click.
- Picks up changes and deletions in Lexware automatically (index refresh on a
  timer, every 10 minutes by default).

## Why a connector, not just a template

The Lexware contacts API **cannot filter by phone number** (only by email, name,
customer number, customer/vendor). A 3CX CRM template looks a caller up *by their
number* — so it cannot query Lexware directly. The connector bridges the gap: it
periodically pulls all contacts, builds an index searchable by phone number and
email, and answers 3CX's lookups in milliseconds.

```
  incoming call
       │
       ▼
   3CX  ──HTTPS──►  https://lexware.example.com   (your reverse proxy, TLS)
                         │
                         ▼
                    connector  ──►  cache (index)
                         ▲
                         │ every 10 min
                    Lexware Office API
```

## Requirements

- A Lexware Office **public API key** — app.lexware.de → Settings → Public API.
- A **3CX** system (cloud-hosted or self-hosted).
- **Docker** (recommended) — or PHP 8.1+ with the `curl` extension.
- A **reverse proxy with TLS** (nginx, Caddy, Traefik, NGINX Proxy Manager, …)
  that is reachable by 3CX. For 3CX **cloud**, the connector must be reachable
  over the public internet; the shared token protects it.

## 🚀 Quick start (Docker)

```bash
git clone https://github.com/Maxcyber86/lexware-office-3cx.git
cd lexware-office-3cx/connector

# 1. Open docker-compose.yml and set, at the top of the file:
#      LEXWARE_API_KEY  = your Lexware public API key
#      ADAPTER_TOKEN    = a shared secret, e.g.:  openssl rand -hex 24

# 2. Start it
docker compose up -d

# 3. Wait for the first index build
docker compose logs -f refresh          # -> "Index written: N records ..."

# 4. Check health (from the host)
curl http://127.0.0.1:8710/health
```

Then publish the connector **behind your reverse proxy with TLS**
(e.g. `https://lexware.example.com`), reachable by 3CX. By default the container
publishes only to `127.0.0.1:8710` (reverse proxy on the same host). To use a
reverse proxy running in Docker, attach the `adapter` service to that proxy's
network instead of publishing the port.

## Import the 3CX template

1. 3CX Admin Console → **Integrations / CRM** → **Add**.
2. Upload **`template/LexwareOffice.xml`**. If 3CX asks for a *package*, put the
   XML together with a plain-text file named `VERSION` (content: `3`) into a ZIP
   and upload that.
3. Open the integration and fill in the two fields:
   - **Adapter URL**: `https://lexware.example.com` (no trailing slash)
   - **Token**: the same value as `ADAPTER_TOKEN`
4. Use the built-in **Test** with a known phone number.

## Configuration

Set via `environment:` in `docker-compose.yml` (Docker) or in `config.php`
(bare-metal). Only the first two are required.

| Variable | Default | Meaning |
|---|---|---|
| `LEXWARE_API_KEY` | – | **Required.** Lexware Office public API key. |
| `ADAPTER_TOKEN` | – | **Required.** Shared secret; must match the Token in the 3CX template. |
| `COUNTRY_CODE` | `+49` | Country code used to normalize national numbers to E.164. |
| `LEXWARE_ONLY_CUSTOMERS` | `false` | `true` = only customers, `false` = all contacts (incl. vendors). |
| `REFRESH_INTERVAL_SECONDS` | `600` | How often the index is rebuilt from Lexware. |
| `ADAPTER_MAX_CACHE_AGE` | `3600` | Reject lookups if the cache is older than this (seconds; `0` = no limit). |
| `LEXWARE_BASE_URL` | `https://api.lexware.io` | API base URL (rarely changed). |
| `LEXWARE_APP_BASE_URL` | `https://app.lexware.de` | Web app base for the screen-pop deep link. |
| `LEXWARE_PAGE_SIZE` / `LEXWARE_PAGE_DELAY_MS` | `250` / `600` | Paging size and delay (respects the API rate limit). |
| `ADAPTER_CACHE_FILE` | `/data/cache.json` | Where the index is stored. |

## How contacts are mapped

- A **person** → `Last, First`.
- A **company with contact persons** → one entry **per contact person**
  (`Last, First (Company)`, with that person's own number) **plus** one entry for
  the company's own number (`Company`). So both the contact person's mobile and
  the company's landline resolve, each to a sensible name.
- The company name is deliberately part of the **display name**, so it appears on
  desk phones and in missed-call emails — 3CX shows only the name there, not a
  separate company field.
- Phone numbers are normalized to **E.164** for matching; a last-8-digits
  fallback catches formatting differences. If several records share those
  digits, the fallback only resolves when they all carry the same full number.
- 3CX inserts the caller number into the lookup URL without encoding, so a
  leading `+` arrives as a space. The connector restores it before matching.
- Numbers returned to 3CX are cleaned and uniform: domestic numbers in national
  format without spaces (`01515550100`, the format German trunks deliver),
  foreign numbers in E.164 (`+41791234567`). No-break spaces and invisible
  bidi/zero-width characters that Lexware sometimes adds are removed — with
  them, 3CX does not add the contact to its phonebook.

## Bare-metal (without Docker)

```bash
cd connector
cp config.example.php config.php     # then edit config.php and: chmod 600 config.php
php lexware-3cx-adapter.php refresh   # build the index once
php -S 127.0.0.1:8710 lexware-3cx-adapter.php   # run the HTTP service
```

For production, use the provided unit files in `connector/systemd/` (a service
for the HTTP endpoint and a timer that runs `refresh` every 10 minutes). The
connector uses `config.php` automatically when no environment variables are set.

Run the internal test suite any time:

```bash
php lexware-3cx-adapter.php selftest
```

## 🔒 Security

- Every lookup requires `?token=…`; `/health` does not (it reveals only a count).
- The connector **never logs** the token or the API key.
- Always put **TLS** in front of it. Optionally restrict access to 3CX's IP
  ranges at your reverse proxy.

## Desk phones

3CX sends the caller name to desk phones (e.g. Yealink) only for numbers found
in its **own phonebook** at the time the call is routed. The live CRM result
appears in the web client, the apps and missed-call emails, but not on the
desk phone. To show names on desk phones as well:

- set the integration's contact lookup to **always query**, and
- enable **"add CRM contacts to the company phonebook"**.

Trade-offs: the name appears on the desk phone from the **second call** of a
number onwards, and the phonebook entries are static copies — later changes in
Lexware are not carried over to them.

## Notes & limitations

- The German mobile-slot heuristic (`15x/16x/17x` → mobile) and the `+49`
  default are for Germany; set `COUNTRY_CODE` for other regions.
- **Not included:** call journaling — Lexware Office is accounting software with
  no call-log/activity object to write to.

## License

MIT — see [`LICENSE`](LICENSE). Without any warranty.

Not affiliated with or endorsed by Lexware / Haufe-Lexware or 3CX.

---

# 🇩🇪 Deutsch

## Was es macht

- Löst eingehende Anrufer zu **Name und Firma** aus deinen Lexware-Office-
  Kontakten auf — im 3CX Web Client, in den Apps und in Verpasst-Anruf-Mails.
  Tischtelefone zeigen den Namen, sobald der Kontakt im 3CX-Telefonbuch steht
  (siehe [Tischtelefone](#tischtelefone)).
- Ergänzt eine **Kontaktsuche** im 3CX-Web-Client („Kontakt aus CRM hinzufügen").
- **Screen-Pop**: öffnet den Kontakt per Klick in Lexware Office.
- Zieht Änderungen und Löschungen in Lexware automatisch nach (Index-Refresh per
  Timer, standardmäßig alle 10 Minuten).

## Warum ein Connector und nicht nur ein Template

Die Lexware-Kontakte-API **kann nicht nach Telefonnummer filtern** (nur nach
E-Mail, Name, Kundennummer, Kunde/Lieferant). Ein 3CX-CRM-Template schlägt den
Anrufer aber *über dessen Nummer* nach — kann Lexware also nicht direkt abfragen.
Der Connector schließt die Lücke: Er zieht periodisch alle Kontakte, baut einen
nach Rufnummer und E-Mail durchsuchbaren Index und beantwortet die 3CX-Abfragen
in Millisekunden.

```
  eingehender Anruf
       │
       ▼
   3CX  ──HTTPS──►  https://lexware.example.com   (dein Reverse Proxy, TLS)
                         │
                         ▼
                    Connector  ──►  Cache (Index)
                         ▲
                         │ alle 10 Min
                    Lexware-Office-API
```

## Voraussetzungen

- Ein Lexware-Office-**API-Key** — app.lexware.de → Einstellungen → Öffentliche API.
- Eine **3CX**-Anlage (Cloud-gehostet oder selbst gehostet).
- **Docker** (empfohlen) — oder PHP 8.1+ mit der `curl`-Erweiterung.
- Ein **Reverse Proxy mit TLS** (nginx, Caddy, Traefik, NGINX Proxy Manager …),
  der von 3CX erreichbar ist. Bei 3CX **Cloud** muss der Connector aus dem
  öffentlichen Internet erreichbar sein; das gemeinsame Token schützt ihn.

## 🚀 Schnellstart (Docker)

```bash
git clone https://github.com/Maxcyber86/lexware-office-3cx.git
cd lexware-office-3cx/connector

# 1. docker-compose.yml öffnen und oben eintragen:
#      LEXWARE_API_KEY  = dein Lexware-API-Key
#      ADAPTER_TOKEN    = ein gemeinsames Geheimnis, z. B.:  openssl rand -hex 24

# 2. Starten
docker compose up -d

# 3. Auf den ersten Index-Aufbau warten
docker compose logs -f refresh          # -> "Index written: N records ..."

# 4. Status prüfen (vom Host)
curl http://127.0.0.1:8710/health
```

Danach den Connector **hinter deinem Reverse Proxy mit TLS** veröffentlichen
(z. B. `https://lexware.example.com`), erreichbar für 3CX. Standardmäßig
veröffentlicht der Container nur auf `127.0.0.1:8710` (Reverse Proxy auf demselben
Host). Läuft dein Reverse Proxy in Docker, hänge den `adapter`-Service stattdessen
an dessen Docker-Netzwerk, statt den Port zu veröffentlichen.

## 3CX-Template importieren

1. 3CX Admin Console → **Integrations / CRM** → **Add**.
2. **`template/LexwareOffice.xml`** hochladen. Fragt 3CX nach einem *Paket*, die
   XML zusammen mit einer Klartextdatei `VERSION` (Inhalt: `3`) in ein ZIP legen
   und dieses hochladen.
3. Integration öffnen und die zwei Felder ausfüllen:
   - **Adapter URL**: `https://lexware.example.com` (ohne Schrägstrich am Ende)
   - **Token**: derselbe Wert wie `ADAPTER_TOKEN`
4. Mit dem eingebauten **Test** und einer bekannten Rufnummer prüfen.

## Konfiguration

Über `environment:` in der `docker-compose.yml` (Docker) oder in `config.php`
(Bare-Metal). Nur die ersten beiden sind Pflicht.

| Variable | Default | Bedeutung |
|---|---|---|
| `LEXWARE_API_KEY` | – | **Pflicht.** Lexware-Office-API-Key. |
| `ADAPTER_TOKEN` | – | **Pflicht.** Gemeinsames Geheimnis; muss dem Token im 3CX-Template entsprechen. |
| `COUNTRY_CODE` | `+49` | Ländervorwahl zur Normalisierung nationaler Nummern auf E.164. |
| `LEXWARE_ONLY_CUSTOMERS` | `false` | `true` = nur Kunden, `false` = alle Kontakte (inkl. Lieferanten). |
| `REFRESH_INTERVAL_SECONDS` | `600` | Intervall, in dem der Index neu gebaut wird. |
| `ADAPTER_MAX_CACHE_AGE` | `3600` | Nachschlagen ablehnen, wenn der Cache älter ist (Sekunden; `0` = ohne Limit). |
| `LEXWARE_BASE_URL` | `https://api.lexware.io` | API-Basis-URL (selten zu ändern). |
| `LEXWARE_APP_BASE_URL` | `https://app.lexware.de` | Web-App-Basis für den Screen-Pop-Deeplink. |
| `LEXWARE_PAGE_SIZE` / `LEXWARE_PAGE_DELAY_MS` | `250` / `600` | Seitengröße und Pause (hält das API-Rate-Limit ein). |
| `ADAPTER_CACHE_FILE` | `/data/cache.json` | Speicherort des Index. |

## Wie Kontakte gemappt werden

- Eine **Person** → `Nachname, Vorname`.
- Eine **Firma mit Ansprechpartnern** → ein Eintrag **je Ansprechpartner**
  (`Nachname, Vorname (Firma)`, mit dessen eigener Nummer) **plus** ein Eintrag
  für die Firmennummer (`Firma`). So lösen sowohl die Handynummer des
  Ansprechpartners als auch die Festnetznummer der Firma auf — jeweils zu einem
  sinnvollen Namen.
- Der Firmenname ist bewusst Teil des **Anzeigenamens**, damit er auf
  Tischtelefonen und in Verpasst-Anruf-Mails erscheint — 3CX zeigt dort nur den
  Namen, kein separates Firmenfeld.
- Für den Abgleich werden Rufnummern auf **E.164** normalisiert; ein Fallback
  über die letzten acht Ziffern fängt Formatabweichungen ab. Teilen sich mehrere
  Einträge diese Ziffern, greift der Fallback nur, wenn alle dieselbe
  vollständige Nummer tragen.
- 3CX setzt die Anrufernummer unkodiert in die Lookup-URL ein, ein führendes `+`
  kommt daher als Leerzeichen an. Der Connector stellt es vor dem Abgleich wieder
  her.
- An 3CX zurückgegebene Nummern sind bereinigt und einheitlich: Inlandsnummern
  national ohne Leerzeichen (`01515550100`, das Format der deutschen
  Rufnummernübermittlung), Auslandsnummern als E.164 (`+41791234567`).
  Geschützte Leerzeichen und unsichtbare Bidi-/Zero-Width-Zeichen, die Lexware
  teils mitliefert, werden entfernt — mit ihnen übernimmt 3CX den Kontakt nicht
  ins Telefonbuch.

## Bare-Metal (ohne Docker)

```bash
cd connector
cp config.example.php config.php     # dann config.php ausfüllen und: chmod 600 config.php
php lexware-3cx-adapter.php refresh   # Index einmal bauen
php -S 127.0.0.1:8710 lexware-3cx-adapter.php   # HTTP-Dienst starten
```

Für den Dauerbetrieb die mitgelieferten Unit-Dateien in `connector/systemd/`
nutzen (ein Service für den HTTP-Endpunkt und ein Timer, der `refresh` alle 10
Minuten ausführt). Der Connector nutzt automatisch `config.php`, sobald keine
Environment-Variablen gesetzt sind.

Interne Testsuite jederzeit ausführbar:

```bash
php lexware-3cx-adapter.php selftest
```

## 🔒 Sicherheit

- Jedes Nachschlagen erfordert `?token=…`; `/health` nicht (es zeigt nur eine
  Anzahl).
- Der Connector **protokolliert** Token und API-Key **nie**.
- Immer **TLS** davorsetzen. Optional den Zugriff am Reverse Proxy auf die
  IP-Bereiche von 3CX beschränken.

## Tischtelefone

3CX schickt den Anrufernamen an Tischtelefone (z. B. Yealink) nur für Nummern,
die beim Routing des Anrufs im **eigenen Telefonbuch** stehen. Das Live-Ergebnis
aus dem CRM erscheint im Web Client, in den Apps und in Verpasst-Anruf-Mails,
nicht aber am Tischtelefon. Damit der Name auch dort erscheint:

- in der Integration die Kontaktabfrage auf **immer abfragen** stellen und
- **„CRM-Kontakte zum Firmentelefonbuch hinzufügen"** aktivieren.

Abwägung: Am Tischtelefon erscheint der Name ab dem **zweiten Anruf** einer
Nummer, und die Telefonbucheinträge sind statische Kopien — spätere Änderungen in
Lexware werden dort nicht nachgezogen.

## Hinweise & Grenzen

- Die deutsche Mobil-Slot-Heuristik (`15x/16x/17x` → mobil) und der `+49`-Default
  sind für Deutschland; für andere Regionen `COUNTRY_CODE` setzen.
- **Nicht enthalten:** Call-Journaling — Lexware Office ist eine Buchhaltung
  ohne Anrufprotokoll-/Aktivitäten-Objekt.

## Lizenz

MIT — siehe [`LICENSE`](LICENSE). Ohne jede Gewähr.

Kein offizielles Produkt von und nicht verbunden mit Lexware / Haufe-Lexware
oder 3CX.
