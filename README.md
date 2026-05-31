# ParkourONE Campaign Tracking

WordPress/WooCommerce-Plugin, das den Kampagnen-Funnel auf den Landingpages misst:
**Landingpage-Besuch → „Probetraining buchen"-Klick → abgeschlossene Probetraining-Buchung** —
pro Kampagne (First-Touch-UTM). Zeigt die Zahlen in einem Admin-Dashboard mit Zeitraum-Auswahl
und stellt sie über eine gesicherte Pull-API für das ONE Statusboard bereit.

## Funktionsumfang
- **Tracking:** consent-gated Visit- & CTA-Klick-Beacon (DSGVO: `po_has_consent('analytics')`, Admin-/Bot-Ausschluss, keine IP/PII), serverseitige Conversion über den WooCommerce-Status `probetraining` (idempotent, mit Fallback für Gratis-/Gutschein-Buchungen).
- **Attribution:** First-Touch-UTM im Cookie `pot_attribution` → beim Checkout als Order-Meta gesichert.
- **Dashboard:** Pro-Kampagne-Tabelle (Visits, Klicks, Buchungen, Conversion-Rate, Drop-off), Presets (heute/7/30 Tage/benutzerdefiniert), AJAX-Refresh, Health-Banner. Unter dem `parkourone`-Menü.
- **Pull-API:** `GET /wp-json/pot/v1/metrics` — Bearer-Authentifizierung (konstantzeit-Vergleich), camelCase-Payload + `generatedAt`. Secret unter *parkourone → API / Statusboard*.

## Anforderungen
- WordPress 6.5+, PHP 8.1+, WooCommerce (HPOS-kompatibel).
- Für die Conversion-Zählung wird der `probetraining`-Order-Status benötigt (vom Plugin `ab-webhook-endpoint`). Fehlt er, degradiert das Plugin sauber und zeigt einen Hinweis.

## Updates
Automatische Updates direkt von GitHub (gleiches Muster wie die anderen ParkourONE-Plugins):
der integrierte Updater (`includes/github-updater.php`) vergleicht stündlich die neueste
Commit-SHA von `main` mit der lokal installierten Version (`.git-version`) und zieht bei
Abweichung den aktuellen Stand. **Ein Push auf `main` rollt das Update auf die WP-Installation aus.**
Optional sofort statt stündlich über den geteilten GitHub-Webhook-Receiver
(`/wp-json/parkourone/v1/github-webhook`). Ein `parkourone_github_token` (geteilt mit den
Schwester-Plugins) hebt das GitHub-API-Rate-Limit an.

## Lizenz
Proprietär — © ParkourONE.
