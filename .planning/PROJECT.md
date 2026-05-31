# ParkourONE Campaign Tracking

## What This Is

Ein WordPress-Plugin für die WooCommerce-Seite `berlin.parkourone.com`, das den Userflow von Marketing-Kampagnen misst. Für jede Kampagne bzw. Landingpage erfasst es drei Trichterstufen — Landingpage-Besuche, Klicks auf den „Probetraining buchen"-Button und tatsächlich abgeschlossene Probetraining-Buchungen — und zeigt sie in einem einfachen Admin-Dashboard mit wählbaren Zeiträumen. Die aggregierten Kennzahlen werden über eine gesicherte Pull-API bereitgestellt, die das externe „ONE Statusboard"-Projekt abrufen kann.

## Core Value

Verlässlich und korrekt zählen, wie viele echte Probetraining-Buchungen aus jeder Kampagne entstehen (Conversion) — und diese Zahl der Kampagne/Landingpage zuordnen. Wenn alles andere wegfällt, muss die Conversion-Attribution stimmen.

## Requirements

### Validated

<!-- Shipped and confirmed valuable. -->

(Noch keine — greenfield. Ship to validate.)

### Active

<!-- Hypotheses until shipped and validated. -->

- [ ] Landingpage-Besuche pro Kampagne erfassen (server-/clientseitig, consent-konform)
- [ ] Klicks auf den „Probetraining buchen"-Button erfassen (Selektor `a[href*="/probetraining-buchen"]`)
- [ ] Abgeschlossene Probetraining-Buchungen als Conversion zählen (WooCommerce-Status `probetraining`, inkl. Gratis/Gutschein-Fallback, doppelzählungssicher)
- [ ] Kampagnen-Attribution via UTM First-Touch (Cookie beim Erstbesuch → als Order-Meta beim Checkout persistieren)
- [ ] Admin-Dashboard mit Kennzahlen pro Kampagne und wählbarem Zeitraum (Visits, Klicks, Buchungen, Conversion-Rate)
- [ ] Gesicherte Pull-API (REST-Lese-Endpoint) liefert aggregierte Kampagnen-Kennzahlen für das Statusboard (Bearer-Secret, konstantzeit-Vergleich, camelCase + `generatedAt`)
- [ ] Bestehendes Theme-Analytics (`analytics-tracker.js` / `wp_po_analytics_events`) ablösen und sauber deaktivieren (keine Doppelzählung)
- [ ] DSGVO-konform: Consent-Gate (`po_has_consent('analytics')`) respektieren, eingeloggte Admins ausschließen, Datensparsamkeit
- [ ] Plugin-Hausstil & Self-Updater (monkeyspk-Repo + `parkourone_github_token`) übernehmen

### Out of Scope

<!-- Explicit boundaries with reasoning. -->

- Statusboard-Empfängerseite (Cron-Pull-Job, DB-Persistenz, Dashboard im Statusboard) — baut der Nutzer in separater Session; hier nur die Bereitstellung
- Push-Webhook an das Statusboard — verworfen zugunsten Pull-API (passt zum bestehenden Snapshot-Pull-Modell des Statusboards, kein neuer Empfänger/Retry nötig)
- Einzel-Event-Export (Roh-Events) über die API — vorerst nur aggregierte Kennzahlen (Datensparsamkeit/DSGVO)
- Tracking anderer Conversions als Probetraining (Workshops, Kurse) — Fokus liegt auf Probetraining
- Eigenes Charting/Visualisierung im Statusboard — entsteht in der Statusboard-Session

## Context

- **Bestehende Theme-Analytics (wird ersetzt):** Das Theme `parkourone-theme` hat testweise ein eigenes Analytics-System — `assets/js/analytics-tracker.js` (trackt `cta_click`, `add_to_cart`), DB-Tabelle `wp_po_analytics_events`, Session-ID `po_analytics_session_id`, Consent-Gate `po_has_consent('analytics')`, Admin-Ausschluss, sowie eine `get_probetraining_count`. Gute Ansätze werden übernommen; der Theme-Tracker wird danach deaktiviert, um Doppelzählung & DSGVO-Konflikte zu vermeiden.
- **Geschwister-Plugins als Stilvorlage:** `Input/ab-webhook-endpoint` (REST-Registrierung, github-updater, Admin-Übersichten, HMAC/`hash_equals`-Muster) und `Input/custom-events-plugin` (Event-CPT, WooCommerce-Cart-Integration, Buchungsflow). Hausstil: Root-`<slug>.php`, `includes/class-<prefix>-*.php`, `<prefix>_init()` auf `plugins_loaded`, Admin unter `parkourone`-Menü, `wp-list-table` + `wp_ajax_`-AJAX.
- **Conversion-Mechanik:** Eine echte Probetraining-Buchung = WooCommerce-Order erreicht Custom-Status `probetraining` (Hook `woocommerce_order_status_probetraining`). Dieser Status wird vom Plugin `ab-webhook-endpoint` (`AB_Custom_Statuses`) registriert → harte Abhängigkeit, graceful degradation nötig, plus Fallback `woocommerce_order_status_changed` für Gratis-/100%-Gutschein-Buchungen. Conversion-Identifier liegen in Order-Item-Meta (`_event_id`, `_event_product_id`, `_event_date`, …).
- **Button:** „Probetraining buchen" ist ein einfacher Anchor auf `/probetraining-buchen/`; CSS-Klassen variieren je Block, aber der `href` ist konstant → robuster Hook `a[href*="/probetraining-buchen"]`.
- **Statusboard-Ziel:** Next.js auf Vercel (Projekt `one-statusboard`), zieht Daten aktuell per Cron (Pull), KEIN Empfänger-Endpoint vorhanden. Auth-Muster zum Spiegeln: `Authorization: Bearer <SECRET>` + konstantzeit-Vergleich (wie `CRON_SECRET`/`timingSafeEqual`). Konsumiert camelCase-Felder, `generatedAt` (ISO-8601), `.passthrough()` (additive Felder erlaubt).
- **Verifizierte Detail-Befunde:** vollständig in `CONTEXT-FINDINGS.md` (17 belegte Architektur-Entscheidungen, 5 Bereichsanalysen, 10 Risiken) — primäre Wissensquelle für die Planung.

## Constraints

- **Tech-Stack**: WordPress-Plugin (PHP), WooCommerce-Store `berlin.parkourone.com`. Kein Build-Step erwartet (Geschwister-Plugins sind reines PHP/JS/CSS).
- **Hausstil**: Muss `ab-webhook-endpoint`/`custom-events-plugin` 1:1 folgen (Datei-/Klassen-/Options-Konventionen, `parkourone`-Admin-Menü, `wp-list-table`, `wp_ajax_`). Custom-DB-Tabelle (`dbDelta`) ist eine bewusste Abweichung vom options-only-Hausstil (für zeitreihenbasierte Date-Range-Queries nötig).
- **Abhängigkeit**: Conversion-Status `probetraining` stammt aus `ab-webhook-endpoint`; bei dessen Deaktivierung/anderer Ladereihenfolge muss das Plugin graceful degradieren (kein Fatal, Fallback-Hook).
- **DSGVO/Datenschutz**: Deutscher Kontext, Consent-Manager vorhanden. Client-Tracking nur mit `po_has_consent('analytics')`, Admins ausschließen, IP/PII vermeiden, Datensparsamkeit (Aggregat statt Roh-Events nach außen).
- **Self-Update**: github-updater gegen `monkeyspk/<slug>`, geteiltes `parkourone_github_token`, `.git-version`-SHA-Schema.
- **API-Sicherheit**: Pull-API niemals `permission_callback => '__return_true'` (Anti-Pattern aus `ab-webhook-endpoint`); echtes Bearer-Secret + `hash_equals`.

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Tracking ersetzt das Theme-Analytics-System | Doppelzählung & DSGVO-Konflikt vermeiden; gute Ansätze (Consent, Session-ID, Selektor, Conversion-Hook) übernehmen | — Pending |
| Kampagnen-Attribution via UTM First-Touch (Cookie → Order-Meta) | Es existiert keine Kampagnen-/UTM-Verknüpfung; First-Touch bindet spätere Buchung sauber an Ursprungskampagne | — Pending |
| Pull-API statt Push-Webhook | Statusboard ist pull-basiert (Cron-Snapshots), kein Empfänger nötig, kein Datenverlust bei Downtime | — Pending |
| Nur aggregierte Kennzahlen nach außen | Kompakt, passt zum Snapshot-Modell, datensparsam (DSGVO) | — Pending |
| Conversion = WooCommerce-Status `probetraining` (+ Fallback) | Einziges verlässliches serverseitiges Signal für echte Buchung; consent-unabhängig | — Pending |
| Custom-DB-Tabelle für Events (bewusste Hausstil-Abweichung) | Visit/Klick-Volumen + Date-Range-Queries sind mit options/post-meta nicht performant | — Pending |
| Hausstil & Self-Updater aus Geschwister-Plugins übernehmen | Konsistenz, geringere Wartungslast, vorhandene Update-Infrastruktur | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-31 after initialization*
