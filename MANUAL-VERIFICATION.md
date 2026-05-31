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
