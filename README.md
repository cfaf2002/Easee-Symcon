# Easee Wallbox für IP-Symcon

Modul zum Auslesen und Steuern einer **Easee-Wallbox** über die Easee Cloud.
Ersetzt die bisherigen sechs Skripte (`Easee_API`, `Easee_TextHelper`, `Easee_Setup`,
`Easee_Update`, `Easee_Action`, `Easee_Dashboard`) durch **eine einzige Instanz**.

## Funktionen

- Status, Ladeleistung, Ladestrom, Phasen und Strom je Phase (L1–L3)
- **Laden starten/pausieren** direkt über den Schalter „Laden“
- **Ladestrom begrenzen** (6–16 A bzw. 6–32 A bei 22 kW)
- **Zeitsteuerung:** Laden nur im Zeitfenster oder „Fertig bis“ mit Ziel-Energie
- **Kabel dauerhaft verriegeln** über einen Schalter
- Session- und Gesamtenergie inkl. Kostenberechnung über einen einstellbaren Strompreis
- **Verbrauch und Kosten pro Tag, Monat und Jahr** – dauerhaft im Symcon-Archiv
- Ladehistorie der letzten 30 Ladevorgänge (Dauer, kWh, Kosten)
- **Eigene Kachel für die Kachel-Visualisierung** mit Start/Pause und Stromgrenze
- Push-Benachrichtigungen bei Ladebeginn, Ladeende und Fehlern
- Grafisches HTML-Dashboard mit 24-h-Leistungsdiagramm und Monatsübersicht
- Automatische Token-Verwaltung (Refresh, Neuanmeldung bei Ablauf)
- Nutzt die **Observations-API** von Easee (der alte `/state`-Endpunkt wurde zum 01.09.2026 abgeschaltet)

## Voraussetzungen

- IP-Symcon ab Version 7.0
- Easee-Konto (Zugang wie in der Easee-App)

## Installation

1. **Kern-Instanzen → Modules → Hinzufügen** und die Git-URL eintragen:
   `https://github.com/cfaf2002/Easee-Symcon`
2. Im Objektbaum **Instanz hinzufügen → „Easee Wallbox“** wählen.
3. Benutzername und Passwort eintragen und **Übernehmen** klicken.
4. Mit **„Verbindung testen / Charger anzeigen“** prüfen, ob die Anmeldung klappt.

Alle Variablen, Profile und Timer werden automatisch angelegt – ein Setup-Skript ist nicht nötig.

## Konfiguration

| Einstellung | Bedeutung |
|---|---|
| Instanz aktiv | Schaltet den zyklischen Abruf ein/aus |
| Benutzername / Passwort | Zugangsdaten des Easee-Kontos |
| Charger-ID | Leer lassen = erster Charger im Konto. Bei mehreren Wallboxen die ID eintragen (z. B. `EH123456`) |
| Abrufintervall | Minuten zwischen zwei Abrufen (Standard 5) |
| Maximale Ladeleistung | Für den Leistungsbalken und die Obergrenze der Stromgrenze (11 kW → 16 A, 22 kW → 32 A) |
| Visualisierung für Push | Kachel-Visualisierung oder WebFront, an die Benachrichtigungen gehen |
| Zeitsteuerung aktivieren | Legt die Variablen für die Zeitsteuerung an |
| Sicherheitspuffer | So viel früher startet „Fertig bis“ als rechnerisch nötig (Standard 30 min) |
| Strompreis | Arbeitspreis in €/kWh für alle Kostenberechnungen |
| Hintergrundbild / abdunkeln / weichzeichnen | Optionales Bild hinter der Kachel (siehe unten) |
| Dashboard-Variable | Legt die HTMLBox-Variable „Dashboard“ an |
| Ladeleistung archivieren | Aktiviert automatisch das Logging von „Ladeleistung“ (für das Diagramm) |
| Energie und Kosten als Zähler archivieren | Archiviert „Gesamtenergie“ und „Kosten gesamt“ als Zähler – daraus bildet Symcon Werte pro Tag, Woche, Monat und Jahr |

## Variablen

| Variable | Typ | Beschreibung |
|---|---|---|
| Status | Integer | Betriebszustand (Offline, Kein Fahrzeug, Wartet auf Start, Lädt, …) |
| Laden | Boolean, schaltbar | Zeigt, ob geladen wird. Einschalten = fortsetzen, Ausschalten = pausieren |
| Ladeleistung | Float (kW) | Aktuelle Leistung |
| Ladestrom / Strom L1–L3 | Float (A) | Ladestrom gesamt und je Phase |
| Ladestrom-Grenze | Integer (A), schaltbar | Maximaler Ladestrom je Phase |
| Phasen | Integer | Anzahl genutzter Phasen |
| Session Energie / Kosten | Float | Energie seit dem Einstecken des Fahrzeugs |
| Gesamtenergie / Gesamtkosten | Float | Zählerstand der Wallbox, Kosten mit aktuellem Preis geschätzt |
| Strompreis | Float (€/kWh) | Anzeige des im Formular eingetragenen Preises |
| Energie heute / Kosten heute | Float | Seit Mitternacht geladen, springt um 0 Uhr auf 0 |
| Energie/Kosten dieser Monat, dieses Jahr | Float | Statistik aus dem Zählerstand |
| Kosten gesamt (seit Installation) | Float (€) | Laufender Kostenzähler, jede kWh mit dem damals gültigen Preis |
| Fahrzeug verbunden | Boolean | |
| Kabel verriegelt | Boolean | Aktueller Zustand |
| Kabel dauerhaft verriegelt | Boolean, schaltbar | Dauerverriegelung ein/aus |
| Smart Charging | Boolean | |
| Online, WLAN Signal, Firmware | | Diagnose |
| Grund für keinen Strom, Fehlercode, Fehlertext | | Diagnose |
| API OK, Letztes Update, Letzter Fehler | | Zustand der Cloud-Verbindung |
| Dashboard | String (~HTMLBox) | Grafische Übersicht (optional) |

Nur bei aktivierter Zeitsteuerung:

| Variable | Typ | Beschreibung |
|---|---|---|
| Zeitsteuerung | Integer, schaltbar | Aus / Zeitfenster / Fertig bis |
| Zeitfenster Beginn / Ende | Uhrzeit, schaltbar | Für den Modus „Zeitfenster“ |
| Fertig bis | Uhrzeit, schaltbar | Für den Modus „Fertig bis“ |
| Ziel-Energie | Float (kWh), schaltbar | So viel soll bis „Fertig bis“ geladen sein |
| Zeitsteuerung Info | String | Was die Zeitsteuerung gerade tut, z. B. „Start um 03:40 (12,0 kWh bis 07:00)“ |

Zugangs-Token, Charger-ID, Ladehistorie und Statistik werden intern an der Instanz gespeichert.

## Ladestrom-Grenze

Die Grenze wird als **dynamischer Ladestrom** an die Wallbox geschickt. Das schont deren internen Speicher,
auch wenn der Wert häufig geändert wird. Nach einem **Neustart der Wallbox** gilt wieder ihr fest eingestellter
Maximalstrom – die Variable zeigt dann nach dem nächsten Abruf den tatsächlichen Wert an.

## Zeitsteuerung

**Zeitfenster:** Das Modul gibt das Laden nur zwischen *Beginn* und *Ende* frei, auch über Mitternacht
(z. B. 22:00–06:00). Außerhalb wird pausiert.

**Fertig bis:** Du legst eine Uhrzeit und eine Ziel-Energie fest (z. B. 20 kWh bis 07:00). Das Modul schätzt aus
Stromgrenze und Phasen die Ladeleistung, rechnet den Sicherheitspuffer dazu und startet **so spät wie möglich**.
Ist das Ziel erreicht, wird pausiert. Läuft die Zeit ab, bevor das Ziel erreicht ist, lädt es weiter.

Gut zu wissen:
- Der Plan gilt pro Einstecken. Nach dem Abstecken beginnt beim nächsten Einstecken ein neuer Plan.
- **Manuelles Schalten** von „Laden“ (Variable, Kachel oder Skript) hat Vorrang, bis das Fahrzeug abgesteckt wird.
- Die Zeitsteuerung prüft jede Minute, erkennt ein neu eingestecktes Fahrzeug aber erst beim nächsten Abruf.
  Beginnt die Wallbox nach dem Einstecken sofort zu laden, wird sie also nach spätestens einem Abrufintervall pausiert.
  Wer die Zeitsteuerung nutzt, stellt das Abrufintervall am besten auf 1–2 Minuten.

## Strompreis

Der Strompreis wird im **Instanz-Formular** unter „Strompreis, Dashboard & Archiv“ eingetragen. Ein neuer Preis gilt ab dem
Übernehmen: Tages-, Monats- und Jahreskosten sowie der Kostenzähler behalten für bereits geladene kWh den alten Preis.
„Session Kosten“ und „Gesamtkosten (geschätzt)“ werden dagegen komplett mit dem neuen Preis neu berechnet.
Beim Update von einer älteren Version wird der bisher in der Variable gepflegte Preis automatisch ins Formular übernommen.

Per Skript: `EASEE_SetEnergyPrice(<InstanzID>, 0.32);`

## Verbrauch pro Tag im Archiv

Das Modul archiviert automatisch **„Gesamtenergie“** und **„Kosten gesamt (seit Installation)“** mit der Aggregation
**Zähler**. Symcon berechnet daraus selbst, wie viel pro Stunde, Tag, Woche, Monat und Jahr geladen wurde und was es gekostet hat –
dauerhaft, auch über Jahre. In der Visualisierung einfach die Variable öffnen und im Diagramm zwischen Tag, Woche,
Monat und Jahr umschalten. Per Skript z. B. die Tageswerte der letzten 30 Tage:

```php
$archiv = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0];
$werte  = AC_GetAggregatedValues($archiv, <ID von Gesamtenergie>, 1 /* Tag */, strtotime('-30 days'), time(), 0);
```

Die Aufzeichnung beginnt mit der Aktivierung – die Easee-API liefert keine Tageswerte der Vergangenheit.

## Statistik

Die Monats- und Jahreswerte werden aus dem **Zählerstand** der Wallbox berechnet und enthalten daher jede
geladene kWh, auch wenn ein Ladevorgang nicht erkannt wurde. Die Kosten werden mit dem jeweils gültigen Strompreis
aufsummiert – eine spätere Preisänderung verändert also vergangene Monate nicht.
Die Statistik beginnt mit der Installation des Moduls; Werte davor sind nicht enthalten.

## Kachel-Visualisierung

Die Instanz bringt eine **eigene Kachel** mit: links ein Leistungsring in der Farbe des aktuellen Status,
rechts eine Grafik von Wallbox und Fahrzeug (beim Laden fließt animiert Strom durchs Kabel, der Akku füllt sich)
und darunter eine Werteliste (aktuelle Ladung, dieser Monat, Anschluss, Stromgrenze mit − / +, Zeitsteuerung)
und darunter der Knopf zum Starten bzw. Pausieren. Einfach die Instanz in der Kachel-Visualisierung hinzufügen.

Die Kachel passt sich der Größe an: breit nebeneinander, schmal untereinander (dann ohne Fahrzeug-Grafik); bei kleinen Kacheln bleiben
Ring, Stromgrenze und Start/Pause. Ist der Ladestrom nicht begrenzt, steht dort „max“.

Auf dem **Handy** (flache Kachel) zeigt die Kachel links den Ring und rechts Ladung, Heute und den Start/Pause-Knopf.
Über das Vergrößern-Symbol oben rechts öffnet Symcon die volle Ansicht mit allen Werten.

### Hintergrundbild

Unter **„Kachel“** im Instanz-Formular kannst du ein eigenes Bild (JPG, PNG oder WebP) direkt auswählen, z. B. ein Foto
deines Carports. Das Bild wird hinter die Kachel gelegt und abgedunkelt (Standard 55 %), damit alle Werte lesbar bleiben;
die Werteliste bekommt dann einen leicht milchigen Glas-Hintergrund. Optional lässt sich das Bild weichzeichnen.
Offene Kacheln übernehmen ein neues Bild sofort nach dem Übernehmen.

Tipp: Querformat, am besten unter 500 KB (z. B. 1600 × 1000 Pixel als JPG) – das Bild wird in der Instanz gespeichert
und beim Öffnen der Kachel mitgeladen. Zum Entfernen das Bild im Feld löschen und übernehmen.

## Ladeende-Erkennung

Ein Ladevorgang gilt erst als beendet, wenn **zwei Abrufe hintereinander** kein Strom fließt.
So zerfällt eine Ladung nicht durch kurze Pausen (z. B. Balancing der Fahrzeugbatterie) in mehrere Einträge.
Wird das Fahrzeug abgesteckt oder „Laden“ pausiert, wird der Vorgang sofort beim nächsten Abruf abgeschlossen.
Wird nach einer Pause weitergeladen, zählt der neue Eintrag in der Historie nur die neu geladene Energie.

Nach jedem Befehl holt das Modul den Zustand nach 15 Sekunden automatisch neu.

## PHP-Befehle

```php
EASEE_Update(int $InstanzID): bool                     // Sofort abrufen
EASEE_StartCharging(int $InstanzID): bool              // Laden fortsetzen
EASEE_StopCharging(int $InstanzID): bool               // Laden pausieren
EASEE_SetChargeLimit(int $InstanzID, int $Ampere): bool
EASEE_SetCableLockPermanent(int $InstanzID, bool $An): bool
EASEE_SetEnergyPrice(int $InstanzID, float $EuroProKWh) // wie das Feld im Formular
EASEE_ListChargers(int $InstanzID): string             // Verbindungstest
EASEE_GetHistory(int $InstanzID): string               // Ladehistorie als JSON
EASEE_ResetHistory(int $InstanzID)
EASEE_ImportHistory(int $InstanzID, string $Json): int // Alte Historie übernehmen
EASEE_GetStatistics(int $InstanzID): string            // Monatswerte als JSON
EASEE_ResetStatistics(int $InstanzID)
```

`StartCharging`/`StopCharging` aus einem Skript gelten wie manuelles Schalten und übersteuern die Zeitsteuerung.

## Umstieg von den alten Skripten

1. Modul installieren und Instanz einrichten (siehe oben) – die Skripte zunächst weiterlaufen lassen.
2. Alte Ladehistorie übernehmen (einmalig in einem Testskript ausführen):
   ```php
   EASEE_ImportHistory(<ID der neuen Instanz>, GetValueString(<ID der alten Variable "Ladehistorie (JSON)">));
   ```
3. Strompreis im Instanz-Formular eintragen.
4. Wenn alles läuft: das zyklische Ereignis unter `Easee_Update` und das unter `Easee_Dashboard` **deaktivieren**,
   danach die sechs Skripte und die alten Variablen löschen.

**Hinweis zu Archivdaten:** Die Instanz legt neue Variablen an. Wer die bisherigen Archivwerte (z. B. Ladeleistung,
Gesamtenergie) behalten möchte, lässt die alten Variablen einfach bestehen und deaktiviert nur das Logging.

**Entfallen** sind die Variablen *Spannung* und *FatalErrorCode* (sie waren seit der Umstellung auf die
Observations-API ohnehin immer 0) sowie die Auswahlvariable *Easee Steuerung* – „Laden“, „Ladestrom-Grenze“
und „Kabel dauerhaft verriegelt“ sind jetzt direkt schaltbar, „Aktualisieren“ gibt es als Button in der Instanz.

## Fehlersuche

- **Status „Anmeldung fehlgeschlagen“:** Zugangsdaten in der Easee-App prüfen.
- **Status „API-Fehler“:** Details stehen in der Variable „Letzter Fehler“.
- Alle Anfragen und Antworten sind im **Debug-Fenster** der Instanz sichtbar (Passwort und Token werden nicht angezeigt).
  Dort protokolliert auch die Zeitsteuerung, wann und warum sie freigibt oder pausiert.
- Bei HTTP 429 (zu viele Anfragen) das Abrufintervall erhöhen.

## Autor

Armin Frohwerk

## Versionen

- **1.9** – Kompakte Handy-Ansicht für flache Kacheln: Ring links, Ladung/Heute und Start/Pause rechts
- **1.8** – Eigenes Hintergrundbild für die Kachel (mit Abdunkeln und Weichzeichnen)
- **1.7** – Energie und Kosten pro Tag (Variablen „heute“, Archiv als Zähler für Tag/Woche/Monat/Jahr), laufender Kostenzähler, Strompreis wird im Instanz-Formular gepflegt, Zeile „Heute“ in Kachel und Dashboard
- **1.6** – Fahrzeug-Grafik zurück in der Kachel (über der Werteliste, Farbe folgt dem Status)
- **1.5** – Kachel neu gestaltet: ruhiges Layout mit Leistungsring und Werteliste, Linien-Icons, sauberes Verhalten bei allen Größen
- **1.4** – Kachel: kein Überlappen mit dem Symcon-Kacheltitel, passende Schrift, wächst mit großen Kacheln mit, „max“ bei unbegrenztem Ladestrom
- **1.3** – Neu gestaltete Kachel mit Leistungsring und animierter Wallbox-/Fahrzeug-Grafik
- **1.2** – Ladestrom-Grenze, Zeitsteuerung (Zeitfenster / Fertig bis), Monats- und Jahresstatistik,
  eigene Kachel für die Kachel-Visualisierung, Historie zählt nach einer Pause nur die neu geladene Energie
- **1.1** – Fehler beim Anlegen der Instanz behoben (Archiv-Logging)
- **1.0** – Erste Version als Modul (Ablösung der Skriptsammlung)
