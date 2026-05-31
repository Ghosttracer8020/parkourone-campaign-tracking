# Manuelle Test-Checkliste (WordPress-Staging)

Dieses Plugin wurde ohne lokale PHP-/WordPress-Laufzeit gebaut. Der gesamte Code ist statisch verifiziert (Struktur, grep-Assertions, Code-Review). Die folgenden **Laufzeit-Checks** musst du einmal auf deinem WordPress-Staging (`berlin.parkourone.com`-Klon) ausführen, bevor das Plugin live geht. Pro Phase gesammelt.

---

## Phase 1 — Plugin Foundation & Events Store

- [ ] Plugin aktivieren (einmal mit aktivem WooCommerce, einmal ohne) → kein Fatal, keine „unexpected output"-Notice
- [ ] `wp db query "SHOW INDEX FROM wp_pot_events"` → Keys `event_type_created`, `campaign_created`, `order_idx` vorhanden
- [ ] Testdaten: 1 visit + 2 clicks + 1 booking für Kampagne `spring` einfügen, dann `POT_Store::aggregate_by_campaign(...)` → visits=1, clicks=2, bookings=1
- [ ] Menüpunkt „Campaign Tracking" unter `parkourone` sichtbar, zeigt „Coming soon — data store ready."
- [ ] `wp cron event list` → `pot_prune_events` täglich nach Aktivierung; nach Deaktivierung weg; bei Reaktivierung genau einmal wieder da
- [ ] 200 Tage altes visit + booking seeden, `wp eval 'POT_Cron::prune_events();'` → visit gelöscht, booking bleibt
- [ ] Mit gesetztem `parkourone_github_token` manuellen Update-Check anstoßen (setzt voraus, dass das Repo `monkeyspk/parkourone-campaign-tracking` existiert)
- [ ] `wp plugin uninstall parkourone-campaign-tracking` → Tabelle gedroppt, `pot_*`-Optionen weg, Cron geleert, `parkourone_github_token` bleibt erhalten

---

## Phase 2 — Conversion & Attribution Bridge

### Conversion-Listener (server-seitig, consent-unabhängig)

- [ ] Bezahlte Probetraining-Bestellung anlegen → Status `probetraining` setzen → **genau eine** `booking`-Zeile in `wp_pot_events` für diese `order_id`
- [ ] Denselben Auftrag aus `probetraining` heraus und wieder hinein transitionieren → **weiterhin nur eine** booking-Zeile (Idempotenz via `_pot_conversion_tracked`)
- [ ] Gratis-/100%-Gutschein-Bestellung, die über den Priority-999-Redirect in `probetraining` landet → genau einmal gezählt (Fallback-Hook `woocommerce_order_status_changed`)
- [ ] `ab-webhook-endpoint` deaktivieren → wp-admin neu laden → dismissible Notice „Probetraining-Status nicht gefunden …" erscheint, `get_option('pot_conversion_status') === 'not_configured'`, **kein Fatal**, keine Zählung
- [ ] WooCommerce deaktivieren → kein Fatal, `pot_conversion_status === 'not_configured'`, keine WC-Hooks registriert

### Attribution-Bridge (Consent-gated First-Touch)

- [ ] End-to-end: Landingpage mit `?utm_campaign=spring&utm_source=fb&utm_medium=cpc` besuchen → Consent erteilen → DevTools: Cookie `pot_attribution` gesetzt (SameSite=Lax, ~90 Tage) → Checkout → Order-Meta `_pot_campaign=spring` (+ source/medium/landing) → Status `probetraining` → `booking`-Zeile mit `campaign='spring'`
- [ ] Vor Consent: NUR `sessionStorage`-Eintrag `pot_attribution_pending`, **kein** Cookie (TTDSG §25)
- [ ] First-touch wins: erneuter Besuch mit `?utm_campaign=summer` → Cookie hält weiterhin `spring`
- [ ] Als eingeloggter Admin → `pot-attribution.js` wird **nicht** enqueued (View Source / Network)
- [ ] Bestellung **ohne** UTM/Cookie → keine `_pot_*`-Order-Meta → booking landet im `(unattributed)`-Bucket in `aggregate_by_campaign`, wird nie verworfen
- [ ] Manipuliertes (Nicht-JSON) `pot_attribution`-Cookie → Checkout → kein Fatal, keine Attribution-Meta geschrieben
