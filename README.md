# Easee Wallbox für IP-Symcon

Modul zum Auslesen und Steuern einer **Easee-Wallbox** über die Easee Cloud.
Ersetzt die bisherigen sechs Skripte (`Easee_API`, `Easee_TextHelper`, `Easee_Setup`,
`Easee_Update`, `Easee_Action`, `Easee_Dashboard`) durch **eine einzige Instanz**.

## Funktionen

- Status, Ladeleistung, Ladestrom, Phasen und Strom je Phase (L1–L3)
- Session- und Gesamtenergie inkl. Kostenberechnung über einen einstellbaren Strompreis
- **Laden starten/pausieren** direkt über den Schalter „Laden“
- **Kabel dauerhaft verriegeln** über einen Schalter
- Ladehistorie der letzten 30 Ladevorgänge (Dauer, kWh, Kosten)
- Push-Benachrichtigungen bei Ladebeginn, Ladeende und Fehlern
- Grafisches HTML-Dashboard mit 24-h-Leistungsdiagramm
- Automatische Token-Verwaltung (Refresh, Neuanmeldung bei Ablauf)
- Nutzt die **Observations-API** von Easee (der alte `/state`-Endpunkt wurde zum 01.09.2026 abgeschaltet)

## Voraussetzungen

- IP-Symcon ab Version 7.0
- Easee-Konto (Zugang wie in der Easee-App)

## Installation

1. **Kern-Instanzen → Modules → Hinzufügen** und die Git-URL eintragen:
   `https://github.com/cfaf2002/Symcon-Easee`
2. Im Objektbaum **Instanz hinzufügen → „Easee Wallbox“** wählen.
3. Benutzername und Passwort eintragen und **Übernehmen** klicken.
4. Mit **„Verbindung testen / Charger anzeigen“** prüfen, ob die Anmeldung klappt.

Alle Variablen, Profile und Timer werden automatisch angelegt – ein Setup-Skript ist nicht mehr nötig.

## Konfiguration

| Einstellung | Bedeutung |
|---|---|
| Instanz aktiv | Schaltet den zyklischen Abruf ein/aus |
| Benutzername / Passwort | Zugangsdaten des Easee-Kontos |
| Charger-ID | Leer lassen = erster Charger im Konto. Bei mehreren Wallboxen die ID eintragen (z. B. `EH123456`) |
| Abrufintervall | Minuten zwischen zwei Abrufen (Standard 5) |
| Maximale Ladeleistung | Nur für den Balken im Dashboard |
| Visualisierung für Push | Kachel-Visualisierung oder WebFront, an die Benachrichtigungen gehen |
| Dashboard-Variable | Legt die HTMLBox-Variable „Dashboard“ an |
| Ladeleistung archivieren | Aktiviert automatisch das Logging von „Ladeleistung“ (für das Diagramm) |

## Variablen

| Variable | Typ | Beschreibung |
|---|---|---|
| Status | Integer | Betriebszustand (Offline, Kein Fahrzeug, Wartet auf Start, Lädt, …) |
| Laden | Boolean, schaltbar | Zeigt, ob geladen wird. Einschalten = fortsetzen, Ausschalten = pausieren |
| Ladeleistung | Float (kW) | Aktuelle Leistung |
| Ladestrom / Strom L1–L3 | Float (A) | Ladestrom gesamt und je Phase |
| Phasen | Integer | Anzahl genutzter Phasen |
| Session Energie / Kosten | Float | Aktueller Ladevorgang |
| Gesamtenergie / Gesamtkosten | Float | Zählerstand der Wallbox, Kosten mit aktuellem Preis geschätzt |
| Strompreis | Float (€/kWh), schaltbar | Änderung berechnet die Kosten sofort neu |
| Fahrzeug verbunden | Boolean | |
| Kabel verriegelt | Boolean | Aktueller Zustand |
| Kabel dauerhaft verriegelt | Boolean, schaltbar | Dauerverriegelung ein/aus |
| Smart Charging | Boolean | |
| Online, WLAN Signal, Firmware | | Diagnose |
| Grund für keinen Strom, Fehlercode, Fehlertext | | Diagnose |
| API OK, Letztes Update, Letzter Fehler | | Zustand der Cloud-Verbindung |
| Dashboard | String (~HTMLBox) | Grafische Übersicht (optional) |

Zugangs-Token, Charger-ID und Ladehistorie werden intern an der Instanz gespeichert
und erscheinen nicht mehr als versteckte Variablen.

## Ladeende-Erkennung

Ein Ladevorgang gilt erst als beendet, wenn **zwei Abrufe hintereinander** kein Strom fließt.
So zerfällt eine Ladung nicht durch kurze Pausen (z. B. Balancing der Fahrzeugbatterie) in mehrere Einträge.
Wird das Fahrzeug abgesteckt oder „Laden“ manuell ausgeschaltet, wird der Vorgang sofort beim nächsten Abruf abgeschlossen.

Nach jedem Befehl holt das Modul den Zustand nach 15 Sekunden automatisch neu.

## PHP-Befehle

```php
EASEE_Update(int $InstanzID): bool                     // Sofort abrufen
EASEE_StartCharging(int $InstanzID): bool              // Laden fortsetzen
EASEE_StopCharging(int $InstanzID): bool               // Laden pausieren
EASEE_SetCableLockPermanent(int $InstanzID, bool $An): bool
EASEE_SetEnergyPrice(int $InstanzID, float $EuroProKWh)
EASEE_ListChargers(int $InstanzID): string             // Verbindungstest
EASEE_GetHistory(int $InstanzID): string               // Ladehistorie als JSON
EASEE_ResetHistory(int $InstanzID)
EASEE_ImportHistory(int $InstanzID, string $Json): int // Alte Historie übernehmen
```

## Umstieg von den alten Skripten

1. Modul installieren und Instanz einrichten (siehe oben) – die Skripte zunächst weiterlaufen lassen.
2. Alte Ladehistorie übernehmen (einmalig in einem Testskript ausführen):
   ```php
   EASEE_ImportHistory(<ID der neuen Instanz>, GetValueString(<ID der alten Variable "Ladehistorie (JSON)">));
   ```
3. Strompreis in der neuen Variable „Strompreis“ eintragen.
4. Wenn alles läuft: das zyklische Ereignis unter `Easee_Update` und das unter `Easee_Dashboard` **deaktivieren**,
   danach die sechs Skripte und die alten Variablen löschen.

**Hinweis zu Archivdaten:** Die Instanz legt neue Variablen an. Wer die bisherigen Archivwerte (z. B. Ladeleistung,
Gesamtenergie) behalten möchte, lässt die alten Variablen einfach bestehen und deaktiviert nur das Logging.

**Entfallen** sind die Variablen *Spannung* und *FatalErrorCode*: Diese Werte liefert die neue Observations-API
von Easee nicht mehr (sie waren seit der Umstellung ohnehin immer 0). Ebenso entfällt die Auswahlvariable
*Easee Steuerung* – „Laden“ und „Kabel dauerhaft verriegelt“ sind jetzt direkt schaltbar,
„Aktualisieren“ gibt es als Button in der Instanz.

## Fehlersuche

- **Status „Anmeldung fehlgeschlagen“:** Zugangsdaten in der Easee-App prüfen.
- **Status „API-Fehler“:** Details stehen in der Variable „Letzter Fehler“.
- Alle Anfragen und Antworten sind im **Debug-Fenster** der Instanz sichtbar (Passwort und Token werden nicht angezeigt).
- Bei HTTP 429 (zu viele Anfragen) das Abrufintervall erhöhen.

## Versionen

- **1.1** – Fehler beim Anlegen der Instanz behoben (Archiv-Logging)
- **1.0** – Erste Version als Modul (Ablösung der Skriptsammlung)
