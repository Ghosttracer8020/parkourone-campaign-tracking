# Requirements: ParkourONE Campaign Tracking

**Defined:** 2026-05-31
**Core Value:** Verlässlich und korrekt zählen, wie viele echte Probetraining-Buchungen aus jeder Kampagne entstehen — und diese der Kampagne/Landingpage zuordnen.

## v1 Requirements

Anforderungen für das erste Release. Jede mappt auf eine Roadmap-Phase.

### Plugin-Fundament

- [ ] **INFRA-01**: Plugin installiert/aktiviert nach Hausstil (Root-`<slug>.php`, `includes/class-<prefix>-*.php`, `<prefix>_init()` auf `plugins_loaded`) ohne Fatal, auch wenn WooCommerce oder `ab-webhook-endpoint` fehlen
- [ ] **INFRA-02**: Plugin deklariert WooCommerce-HPOS-Kompatibilität (`custom_order_tables`); jeder Order-Zugriff über `wc_get_order()` (nie `get_post_meta`)
- [ ] **INFRA-03**: GitHub-Self-Updater gegen `monkeyspk/<slug>` mit geteiltem `parkourone_github_token` + `.git-version`-SHA-Schema
- [ ] **INFRA-04**: Custom-Events-Tabelle wird bei Aktivierung via `dbDelta` angelegt, mit Composite-Indizes `(event_type, created_at)` und `(campaign, created_at)`
- [ ] **INFRA-05**: Täglicher Cron entfernt rohe Visit/Klick-Events älter als das Retention-Fenster (Default ~180 Tage); Uninstall entfernt Tabelle + Optionen

### Funnel-Erfassung

- [ ] **CAPTURE-01**: Landingpage-Besuch wird als client-seitiges Beacon an einen dynamischen REST-Endpoint gemeldet (cache-sicher), ein Event je Seitenaufruf
- [ ] **CAPTURE-02**: Klick auf „Probetraining buchen" (`a[href*="/probetraining-buchen"]`) wird per delegiertem Capture-Phase-Listener erfasst
- [ ] **CAPTURE-03**: Client-Tracking feuert nur, wenn `po_has_consent('analytics')` zugestimmt ist
- [ ] **CAPTURE-04**: Eingeloggte Admins (`manage_options`) werden vom Tracking ausgeschlossen
- [ ] **CAPTURE-05**: Bekannte Bots/Crawler werden aus Visit/Klick-Zählung gefiltert (UA-Denylist)

### Conversion

- [ ] **CONVERT-01**: Abgeschlossene Probetraining-Buchung wird gezählt, wenn eine WooCommerce-Order den Status `probetraining` erreicht (server-seitig, consent-unabhängig)
- [ ] **CONVERT-02**: Gratis-/100%-Gutschein-Buchungen werden über den Fallback `woocommerce_order_status_changed` (new_status === `probetraining`) erfasst
- [ ] **CONVERT-03**: Jede Buchung wird genau einmal gezählt (Order-Meta-Idempotenz-Flag, keine Doppelzählung)
- [ ] **CONVERT-04**: Ist der Status `probetraining` nicht verfügbar (`ab-webhook-endpoint` inaktiv), degradiert das Plugin sauber (kein Fatal) und zeigt einen Admin-Hinweis / `not_configured`-Zustand

### Attribution

- [ ] **ATTRIB-01**: First-Touch-UTM (`utm_campaign/source/medium`) wird beim Erstbesuch erfasst und in einem First-Party-Cookie gespeichert (Cookie wird erst nach Consent geschrieben; UTM bis dahin nur im JS-Speicher)
- [ ] **ATTRIB-02**: Gespeicherte UTM wird beim Checkout als Order-Meta persistiert (`woocommerce_checkout_create_order` / `update_order_meta`)
- [ ] **ATTRIB-03**: Visits, Klicks und Buchungen werden über den First-Touch-Wert nach Kampagne gruppiert
- [ ] **ATTRIB-04**: Events/Buchungen ohne UTM-Kampagne landen in einem benannten Bucket „Direct / kein UTM"

### Dashboard

- [ ] **DASH-01**: Admin-Dashboard-Seite unter dem bestehenden `parkourone`-Menü (`manage_options`) zeigt eine Pro-Kampagne-Tabelle (Visits, Klicks, Buchungen)
- [ ] **DASH-02**: Tabelle zeigt Conversion-Rate und Stufen-Drop-off (Visit→Klick, Klick→Buchung) je Kampagne
- [ ] **DASH-03**: Zeitraum-Auswahl mit Presets (heute / 7 T / 30 T / benutzerdefiniert), Default 30 Tage, steuert die Queries
- [ ] **DASH-04**: Dashboard lädt/aktualisiert Daten via AJAX (`wp_ajax_`, Nonce + Capability-Check)

### Pull-API

- [ ] **API-01**: Gesicherter REST-Endpoint liefert aggregierte Pro-Kampagne-Kennzahlen für einen angefragten Zeitraum
- [ ] **API-02**: Endpoint authentifiziert via geteiltem Bearer-Secret, verglichen mit `hash_equals` (nie `__return_true`); unautorisiert → 401
- [ ] **API-03**: Payload nutzt camelCase-Felder und enthält `generatedAt` (ISO-8601), Statusboard-kompatibel (`.passthrough()`)
- [ ] **API-04**: Bearer-Secret liegt in einer eigenen Option (bei Aktivierung via `wp_generate_password` erzeugt), in den Einstellungen einseh-/neu-generierbar

### Migration (Theme-Analytics-Ablösung)

- [ ] **MIGRATE-01**: Die bestehende Theme-Analytics-Emission (CTA-/Visit-Tracking in `analytics-tracker.js`) wird deaktiviert/dedupliziert, sodass nur ein Tracker läuft — keine Doppelzählung
- [ ] **MIGRATE-02**: Cut-over wird per Paritäts-Check verifiziert, sodass keine Tracking-Lücke entsteht

## v2 Requirements

Bewusst zurückgestellt — getrackt, aber nicht in der aktuellen Roadmap.

### Analyse-Komfort

- **COMPARE-01**: Vergleich zum Vorzeitraum (Delta + %) — billig, sobald Date-Range-Queries existieren
- **UNIQUE-01**: Umschalter eindeutige vs. gesamte Visits (Default Conversion-Rate auf eindeutigen Visits)
- **EXPORT-01**: CSV-Export der Pro-Kampagne-Tabelle (nur Aggregat)

### Betrieb

- **HEALTH-01**: API-Auslieferungs-Health-Indikator im Admin (letzter Abruf / letzter Fehler)
- **RETAIN-01**: Konfigurierbares Retention-Fenster als UI (statt hartem Default)
- **LANDING-01**: Pro-Landingpage-Unteraufschlüsselung unterhalb der Kampagne

## Out of Scope

Explizit ausgeschlossen — dokumentiert gegen Scope-Creep (Anti-Features aus Research).

| Feature | Reason |
|---------|--------|
| Multi-Touch / Last-Touch / lineare Attribution | Hohe Komplexität (Pfad-Speicherung, Windows); widerspricht entschiedenem First-Touch-Modell; PII-Risiko |
| Konfigurierbare Attributions-Windows als UI | Config-Fläche + Edge-Cases für Single-Store/kurzen Funnel; sane Default hartkodieren |
| Roh-Event-Export über die API | Verletzt Datensparsamkeit/DSGVO; macht Aggregat-API zur PII-Pipe |
| Individuelle User-Journeys / Session-Replay / User-Level-Dashboards | PII-lastig, consent-fragil, hoher Storage, keine Relevanz für Kampagnen-Counts |
| Charts/Grafiken im WP-Admin | Keine In-House-Chart-Lib; CDN = Datenschutz/Wartung; Zahl passt in Tabelle. Visualisierung = Aufgabe des Statusboards |
| Tracking anderer Conversions (Workshops, Kurse, Add-to-Cart) | Verwässert Fokus; andere Hooks/Meta; Scope = nur Probetraining |
| Echtzeit-/Live-Dashboard | Polling/Websocket-Komplexität, kein Bedarf bei täglichem Pull |
| Generisches Event-Tracking („track any click/page") | Wird zum GA-Klon; sprengt Consent/Storage/UI |
| Cross-Device / Fingerprint-Identity-Stitching | Fingerprinting = DSGVO-Red-Flag; unverhältnismäßig |
| Push-Webhook an Statusboard | Verworfen zugunsten Pull-API (Statusboard ist pull-basiert) |

## Traceability

Welche Phasen welche Anforderungen abdecken.

| Requirement | Phase | Status |
|-------------|-------|--------|
| INFRA-01 | Phase 1 | Pending |
| INFRA-02 | Phase 1 | Pending |
| INFRA-03 | Phase 1 | Pending |
| INFRA-04 | Phase 1 | Pending |
| INFRA-05 | Phase 1 | Pending |
| CONVERT-01 | Phase 2 | Pending |
| CONVERT-02 | Phase 2 | Pending |
| CONVERT-03 | Phase 2 | Pending |
| CONVERT-04 | Phase 2 | Pending |
| ATTRIB-01 | Phase 2 | Pending |
| ATTRIB-02 | Phase 2 | Pending |
| ATTRIB-03 | Phase 2 | Pending |
| ATTRIB-04 | Phase 2 | Pending |
| CAPTURE-01 | Phase 3 | Pending |
| CAPTURE-02 | Phase 3 | Pending |
| CAPTURE-03 | Phase 3 | Pending |
| CAPTURE-04 | Phase 3 | Pending |
| CAPTURE-05 | Phase 3 | Pending |
| MIGRATE-01 | Phase 3 | Pending |
| MIGRATE-02 | Phase 3 | Pending |
| DASH-01 | Phase 4 | Pending |
| DASH-02 | Phase 4 | Pending |
| DASH-03 | Phase 4 | Pending |
| DASH-04 | Phase 4 | Pending |
| API-01 | Phase 5 | Pending |
| API-02 | Phase 5 | Pending |
| API-03 | Phase 5 | Pending |
| API-04 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 28 total
- Mapped to phases: 28 ✓
- Unmapped: 0

---
*Requirements defined: 2026-05-31*
*Last updated: 2026-05-31 after roadmap creation (phase mappings applied)*
