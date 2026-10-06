# Easee Wallbox für IP-Symcon

[![IP-Symcon ab 8.2](https://img.shields.io/badge/IP--Symcon-ab_8.2-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.5 (Build 11)](https://img.shields.io/badge/Modul--Version-1.5_(Build_11)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/Easee-Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/Easee-Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
![Sprache: Deutsch](https://img.shields.io/badge/Sprache-Deutsch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
![Easee Cloud](https://img.shields.io/badge/Cloud-Easee_Observations--API-lightgrey.svg)

Modul zum Auslesen und Steuern einer **Easee-Wallbox** über die Easee Cloud.
Ersetzt die bisherigen sechs Skripte (`Easee_API`, `Easee_TextHelper`, `Easee_Setup`,
`Easee_Update`, `Easee_Action`, `Easee_Dashboard`) durch **eine einzige Instanz**.

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionen](#funktionen)
2. [Voraussetzungen und Technik](#voraussetzungen-und-technik)
3. [Installation](#installation)
4. [Konfiguration](#konfiguration)
5. [Variablen und Darstellungen](#variablen-und-darstellungen)
6. [Ladestrom-Grenze](#ladestrom-grenze)
7. [Zeitsteuerung](#zeitsteuerung)
8. [Eigenes Auto oder Gastladung](#eigenes-auto-oder-gastladung)
9. [Akkustand des Fahrzeugs (optional)](#akkustand-des-fahrzeugs-optional)
10. [Lade-Erinnerung am Abend](#lade-erinnerung-am-abend)
11. [Strompreis](#strompreis)
12. [Verbrauch pro Tag im Archiv](#verbrauch-pro-tag-im-archiv)
13. [Statistik](#statistik)
14. [Kachel-Visualisierung](#kachel-visualisierung)
15. [Ladeende-Erkennung](#ladeende-erkennung)
16. [PHP-Befehle](#php-befehle)
17. [Umstieg von den alten Skripten](#umstieg-von-den-alten-skripten)
18. [Sicherheit und Geschwindigkeit](#sicherheit-und-geschwindigkeit)
19. [Fehlersuche](#fehlersuche)
20. [Entwicklung und Tests](#entwicklung-und-tests)
21. [Changelog](#changelog)
22. [Lizenz](#lizenz)

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
- **Farbschema der Kachel:** Symcon-Design, Dunkel oder Hell
- Push-Benachrichtigungen bei Ladebeginn, Ladeende und Fehlern
- **Lade-Erinnerung am Abend:** Meldung, wenn das Auto ab 21 Uhr zu Hause steht, aber nicht angesteckt ist
- Grafisches HTML-Dashboard mit 24-h-Leistungsdiagramm und Monatsübersicht
- Automatische Token-Verwaltung (Refresh, Neuanmeldung bei Ablauf)
- Nutzt die **Observations-API** von Easee (der alte `/state`-Endpunkt wurde zum 01.09.2026 abgeschaltet)

## Voraussetzungen und Technik

- IP-Symcon ab Version **8.2**, empfohlen **9.0**
- Easee-Konto (Zugang wie in der Easee-App)

Das Modul nutzt die aktuelle Symcon-Technik:

| Technik | Ab Symcon | Wofür |
|---|---|---|
| Basisklasse `IPSModuleStrict` | 8.1 | Strenge Typen in allen Modulfunktionen, robust unter PHP 8.5 (Symcon 9.0) |
| Darstellungen statt Variablenprofilen | 8.0 | Aufzählung mit Symbolen und Farben (Status, Zeitsteuerung), Schalter (Laden, Kabelverriegelung), Schieberegler (Ladestrom-Grenze, Ziel-Energie, Ziel-Akkustand), Datum/Uhrzeit (Zeitfenster, Fertig bis), Wertanzeige mit Vorlage „Batterie“ (Akkustand) |
| HTML-SDK-Kachel | 7.1 | Eigene Kachel mit Live-Aktualisierung über `UpdateVisualizationValue` und Bedienung über `requestAction` |
| Design der Visualisierung | 9.0 | Farbschema „Symcon-Design“ übernimmt Schrift- und Akzentfarbe der gewählten Visualisierung; alternativ feste Schemas „Dunkel“ und „Hell“ |

Eigene Variablenprofile legt das Modul nicht mehr an. Bestehende Variablen bekommen beim Update automatisch die neue
Darstellung; die alten Profile `EaseeWB.*` werden nicht mehr benutzt und können unter *Kern Instanzen → Profile* gelöscht werden.

## Installation

1. **Kern-Instanzen → Modules → Hinzufügen** und die Git-URL eintragen:
   `https://github.com/cfaf2002/Easee-Symcon`
2. Im Objektbaum **Instanz hinzufügen → „Easee Wallbox“** wählen.
3. Benutzername und Passwort eintragen und **Übernehmen** klicken.
4. Mit **„Verbindung testen / Charger anzeigen“** prüfen, ob die Anmeldung klappt.

Alle Variablen und Timer werden automatisch angelegt – ein Setup-Skript ist nicht nötig.

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
| Farbschema der Kachel | Symcon-Design (Farben der Visualisierung), Dunkel oder Hell |
| Hintergrundbild / abdunkeln / weichzeichnen | Optionales Bild hinter der Kachel (siehe unten) |
| An der Wallbox lädt | Eigenes Auto oder Gastladung – siehe unten |
| Akkustand Fahrzeug | Schalter, Quellvariable (0–100 %) und nutzbare Akkugröße – siehe unten |
| Dashboard-Variable | Legt die Variable „Dashboard“ (Darstellung Webinhalt) an |
| Ladeleistung archivieren | Aktiviert automatisch das Logging von „Ladeleistung“ (für das Diagramm) |
| Energie und Kosten als Zähler archivieren | Archiviert „Gesamtenergie“ und „Kosten gesamt“ als Zähler – daraus bildet Symcon Werte pro Tag, Woche, Monat und Jahr |

## Variablen und Darstellungen

Alle Variablen nutzen **Darstellungen** (Symcon ≥ 8.0): Der Status erscheint als Aufzählung mit Symbol und Farbe, „Laden“ und „Kabel dauerhaft verriegelt“ als Schalter, die Ladestrom-Grenze als Schieberegler, Zeitpunkte als Datum/Uhrzeit.

| Variable | Typ | Beschreibung |
|---|---|---|
| Status | Integer | Betriebszustand (Offline, Kein Fahrzeug, Wartet auf Start, Lädt, …) |
| Laden | Boolean, schaltbar | Zeigt, ob geladen wird. Einschalten = fortsetzen, Ausschalten = pausieren |
| Ladeleistung | Float (kW) | Aktuelle Leistung |
| Ladestrom / Strom L1–L3 | Float (A) | Ladestrom gesamt und je Phase |
| Ladestrom-Grenze | Integer (A), schaltbar | Maximaler Ladestrom je Phase |
| Phasen | Integer | Anzahl genutzter Phasen |
| Session Energie / Kosten | Float | Energie seit dem Einstecken des Fahrzeugs (Maximum aus Easee-Session-Zähler, Gesamtzähler-Differenz und aufsummierter Ladeleistung) |
| Gesamtenergie / Gesamtkosten | Float | Zählerstand der Wallbox, Kosten mit aktuellem Preis geschätzt |
| Strompreis | Float (€/kWh) | Anzeige des im Formular eingetragenen Preises |
| Energie heute / Kosten heute | Float | Seit Mitternacht geladen, springt um 0 Uhr auf 0 |
| Energie/Kosten dieser Monat, dieses Jahr | Float | Statistik aus dem Zählerstand |
| Kosten gesamt (seit Installation) | Float (€) | Laufender Kostenzähler, jede kWh mit dem damals gültigen Preis |
| Fahrzeug verbunden | Boolean | |
| Lade-Erinnerung | Boolean | OK / Bitte anstecken (nur mit eingeschalteter Lade-Erinnerung) |
| Kabel verriegelt | Boolean | Aktueller Zustand |
| Kabel dauerhaft verriegelt | Boolean, schaltbar | Dauerverriegelung ein/aus |
| Smart Charging | Boolean | |
| Online, WLAN Signal, Firmware | | Diagnose |
| Grund für keinen Strom, Fehlercode, Fehlertext | | Diagnose |
| API OK, Letztes Update, Letzter Fehler | | Zustand der Cloud-Verbindung |
| Dashboard | String | Grafische Übersicht (optional, Darstellung „Webinhalt“) |

Nur bei aktivierter Zeitsteuerung:

| Variable | Typ | Beschreibung |
|---|---|---|
| Zeitsteuerung | Integer, schaltbar | Aus / Zeitfenster / Fertig bis (Tasten) |
| Zeitfenster Beginn / Ende | Uhrzeit, schaltbar | Für den Modus „Zeitfenster“ |
| Fertig bis | Uhrzeit, schaltbar | Für den Modus „Fertig bis“ |
| Ziel-Energie | Float (kWh), Schieberegler 1–100 | So viel soll bis „Fertig bis“ geladen sein |
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

## Eigenes Auto oder Gastladung

In der Instanz unter **„Fahrzeug“ → „An der Wallbox lädt“** wird fest eingestellt, wer lädt – ganz klar entweder / oder:

- **Eigenes Auto:** Hier wird gepflegt, woher der Akkustand kommt (Schalter, Variable, nutzbare Akkugröße). Die Kachel zeigt
  Akkustand und – falls hinterlegt – das eigene Fahrzeugbild.
- **Gastladung (fremde Fahrzeuge):** Kein Akkustand, kein eigenes Fahrzeugbild (neutrales Auto), „Fertig bis“ arbeitet nur
  mit dem kWh-Ziel. Die Akku-Felder werden im Formular ausgeblendet. Die Einstellung gilt dauerhaft, auch nach dem Abstecken,
  bis sie in der Instanz wieder umgestellt wird. Energie und Kosten werden weiter gezählt.

In der Kachel selbst lässt sich das bewusst nicht umschalten.

## Akkustand des Fahrzeugs (optional)

Eine AC-Wallbox erfährt vom Auto nicht, wie voll der Akku ist. Liegt der Akkustand aber schon in Symcon vor
(z. B. über ein Modul für die Hersteller-Cloud des Autos), kann das Modul ihn anzeigen und nutzen:

1. Im Formular unter **„Akkustand Fahrzeug“** den Schalter aktivieren.
2. Die **Variable mit dem Akkustand** (0–100 %) auswählen.
3. Die **nutzbare Akkugröße** in kWh eintragen (nur für „Fertig bis“ in Prozent nötig).

Dann gibt es die Variable **„Akkustand Fahrzeug“**, eine Zeile **„Akku“** mit Balken in Kachel und Dashboard, und der Akku im
Auto-Symbol zeigt den echten Füllstand. Bei aktiver Zeitsteuerung kommt **„Ziel-Akkustand“** dazu: Im Modus „Fertig bis“ lädt die
Wallbox dann bis zu diesem Prozentwert statt bis zu einer kWh-Menge (Ladezeit geschätzt aus Akkugröße, Ladeleistung und ca. 10 %
Ladeverlusten) und pausiert, sobald das Ziel erreicht ist. Das Modul reagiert sofort, wenn sich die Quellvariable ändert.

Ohne aktivierten Schalter bleibt alles wie bisher – nicht jedes Auto bietet eine Schnittstelle.

## Lade-Erinnerung am Abend

Steht das eigene Auto abends **am Ort der Location Control**, ist aber **nicht an der Wallbox angesteckt**, gibt es eine Meldung
in der Visualisierung. Einrichten in der Instanz unter **„Lade-Erinnerung am Abend“**:

| Einstellung | Bedeutung |
|---|---|
| Melden, wenn … | Schalter für die Erinnerung |
| Ab Uhrzeit | Standard **21:00**; die Erinnerung gilt bis 6 Uhr morgens |
| „Zu Hause“ erkennen über | **Ja/Nein-Variable**, z. B. „Zu Hause“ der Volvo-Instanz, oder **Breiten- und Längengrad**: Dann rechnet das Modul selbst die Entfernung zum Standort unter Kern-Instanzen → Location Control |
| Umkreis | Nur bei Breite/Länge: so nah muss das Auto am Standort sein (Standard 150 m) |
| Zeit zum Anstecken | Nach der Ankunft wird so lange gewartet, bevor gemeldet wird (Standard 10 Minuten) |
| Nur melden, wenn der Akkustand unter | Mit Akkustand: bei vollem Akku keine Meldung (Standard 100 % = immer, wenn nicht voll) |

**So meldet das Modul**
- **Einmal pro Abend** an die Visualisierung unter „Benachrichtigungen“, ohne Eintrag an die erste Kachel-Visualisierung. Ein Tipp auf die
  Meldung öffnet die Wallbox. Registrierte Handys bekommen sie als Push-Nachricht.
- In der **Kachel** erscheint oben der Hinweis **„Bitte anstecken“**, bis das Auto angesteckt ist oder wegfährt.
- Die Variable **„Lade-Erinnerung“** (OK / Bitte anstecken) steht für eigene Ereignisse und Abläufe bereit.
- Kommt das Auto erst später nach Hause, z. B. um 22:30, wird nach der eingestellten Wartezeit gemeldet. Nach Mitternacht zählt es
  noch zum selben Abend, es gibt also keine zweite Meldung.
- Bei **Gastladung** ist die Erinnerung aus, sie gilt nur für das eigene Auto.

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

### Farbschema

Unter **„Kachel“ → „Farbschema der Kachel“**:

- **Symcon-Design** (Standard): Die Kachel übernimmt Schrift- und Akzentfarbe der gewählten Visualisierung – hell wie dunkel.
- **Dunkel** und **Hell**: feste Schemas mit eigenem Hintergrund, unabhängig vom Design der Visualisierung.

Ist ein Hintergrundbild gesetzt, ist die Schrift immer hell. Die Systemeinstellung „Bewegung reduzieren“ wird beachtet,
und solange die Kachel nicht zu sehen ist, ruhen alle Animationen.

### Fahrzeugbild und Ladefortschritt

Das Auto-Symbol zeigt einen **Akku mit Ladefortschritt**: den Akkustand des Fahrzeugs (falls aktiviert), sonst bei „Fertig bis“
den Fortschritt zum kWh-Ziel. Ohne beides pulsiert der Akku nur, solange geladen wird. Beim Laden fließt der Strom animiert
durch das Kabel von der Wallbox bis zum Ladeanschluss.

Statt des eingebauten Symbols kann unter **„Kachel“** ein **eigenes Fahrzeugbild** gewählt werden, z. B. ein Foto deines Autos:

- Am besten ein **freigestelltes PNG** in Seitenansicht (transparenter Hintergrund). Große Bilder werden automatisch auf 800 Pixel verkleinert.
- **Bild spiegeln**, falls die Seite mit dem Ladeanschluss sonst von der Wallbox weg zeigt.
- **Ladeanschluss von links / von oben** in Prozent des angezeigten Bildes – dorthin führt das Kabel. Nach dem Übernehmen sieht
  man das Ergebnis sofort in der Kachel und kann nachjustieren.

Der Akku wird bei einem eigenen Bild als kleine Anzeige unter dem Fahrzeug eingeblendet.

### Hintergrundbild

Unter **„Kachel“** im Instanz-Formular kannst du ein eigenes Bild (JPG, PNG oder WebP) direkt auswählen, z. B. ein Foto
deines Carports. Das Bild wird hinter die Kachel gelegt und abgedunkelt (Standard 55 %), damit alle Werte lesbar bleiben;
die Werteliste bekommt dann einen leicht milchigen Glas-Hintergrund. Optional lässt sich das Bild weichzeichnen.
Offene Kacheln übernehmen ein neues Bild sofort nach dem Übernehmen.

Tipp: Querformat. Große Fotos werden beim ersten Anzeigen einmal auf 1600 Pixel verkleinert und so zwischengespeichert –
die Kachel lädt dadurch auch mit einem Handyfoto schnell. Zum Entfernen das Bild im Feld löschen und übernehmen.

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

## Sicherheit und Geschwindigkeit

**Sicherheit**

- Zugangsdaten stehen nur in der Instanz (Passwortfeld). Passwort und Tokens erscheinen nicht im Debug-Fenster.
- Verbindungen zur Easee-Cloud nur über HTTPS mit geprüftem Zertifikat (`CURLOPT_PROTOCOLS`, `SSL_VERIFYPEER`, `SSL_VERIFYHOST`).
- `RequestAction` nimmt nur bekannte Aktionen an und prüft die Werte: falsche Typen und unbekannte Aktionen werden abgelehnt,
  Ladestrom, Ziel-Energie und Ziel-Akkustand werden auf ihren gültigen Bereich begrenzt.
- Die Kachel setzt alle Werte per `textContent`, nie als HTML. Die Startdaten werden mit `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`
  eingebettet, damit kein Wert (z. B. ein Charger-Name) das Skript der Kachel beenden kann. Ungültige Zeichen aus der Cloud werden ersetzt,
  statt die Kachel abbrechen zu lassen. Die Tests prüfen beides.
- Hochgeladene Bilder werden nur eingebettet, wenn es wirklich Bilder sind (PNG, JPEG, WebP, GIF) – andere Dateien wie SVG oder HTML werden verworfen.
- Die Kommunikation der Kachel ist durch das Passwort der Visualisierung geschützt. Kein `eval`, keine fremden Skripte in der Kachel.

**Geschwindigkeit**

- Die Easee-Cloud wird nur im eingestellten Intervall abgefragt; nach einem Befehl einmalig nach 15 s. Antworten werden komprimiert (gzip) übertragen.
- Variablen werden nur geschrieben, wenn sich ihr Wert ändert. Ein Abruf ohne Änderung löst keine Dutzende Ereignisse und Nachrichten mehr aus.
- Die Kachel bekommt nur dann Daten, wenn sich etwas Sichtbares geändert hat – bei vielen offenen Kacheln spart das spürbar Last.
- Bilder für Kachel und Hintergrund werden einmal verkleinert und zwischengespeichert, statt bei jedem Öffnen mehrere MB zu übertragen.
- Status- und Moduswerte kommen aus festen Tabellen im Modul statt über `GetValueFormatted`.
- Animationen ruhen, solange die Kachel nicht sichtbar ist.

## Fehlersuche

- **Status „Anmeldung fehlgeschlagen“:** Zugangsdaten in der Easee-App prüfen.
- **Status „API-Fehler“:** Details stehen in der Variable „Letzter Fehler“.
- Alle Anfragen und Antworten sind im **Debug-Fenster** der Instanz sichtbar (Passwort und Token werden nicht angezeigt).
  Dort protokolliert auch die Zeitsteuerung, wann und warum sie freigibt oder pausiert.
- Bei HTTP 429 (zu viele Anfragen) das Abrufintervall erhöhen.

## Entwicklung und Tests

| Pfad | Inhalt |
|---|---|
| `EaseeWallbox/` | Modul: Abruf, Variablen, Zeitsteuerung, Lade-Erinnerung (`EaseeReminder.php`), Dashboard und Kachel (`tile.html`) |
| `tests/bootstrap.php` | Testumgebung ohne Symcon (bildet `IPSModuleStrict` nach) |
| `tests/run.php` | Testsuite mit simulierter Easee-Cloud |
| `tests/stubs.php` | Ladetest mit den offiziellen [Symcon-Stubs](https://github.com/symcon/SymconStubs) |

```
php tests/run.php
php tests/stubs.php <Pfad zu SymconStubs>
```

Die Testsuite prüft unter anderem Abruf und Statistik, Ladestrom-Grenze, Zeitfenster und „Fertig bis“ (in kWh und Prozent),
manuelles Übersteuern, Ladehistorie mit Pausen, Tageswerte und Strompreis, Akkustand und Gastladung, die geschätzte Session-Energie,
die Darstellungen der Variablen, abgelehnte Aktionen und Werte, den Schutz der Kachel vor eingeschleustem HTML, sparsame Kachel-Updates,
das Verkleinern und Zwischenspeichern von Bildern sowie das Farbschema. Mit `DEBUG=1` werden die Debug-Ausgaben angezeigt.

`tests/stubs.php` lädt die Bibliothek mit den offiziellen Symcon-Stubs wie Symcon selbst, legt eine Instanz an, öffnet das Formular
und prüft Variablen, Darstellungen und Kachel.

GitHub Actions (`.github/workflows/tests.yml`) prüft bei jedem Push mit PHP 8.3 und 8.5 die Syntax, alle JSON-Dateien, die Testsuite und den Ladetest.

## Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 1.5 | 11 | 06.10.2026 | Hausstil: Regel für die Modulliste (`vendor` gesetzt, höchstens ein Alias) in `STYLEGUIDE.md` und Strukturprüfung ergänzt |
| 1.5 | 10 | 06.10.2026 | Einheitliches Design nach `STYLEGUIDE.md`: Kachel-Grundlage (Farben, Schrift, Radien, Zustandsfarben) und Einstellung „Farbschema der Kachel“ (Symcon-Design, Dunkel, Hell); Kachel-Datei heißt `tile.html`; einheitliche Badges; gemeinsamer Test-Workflow mit Struktur- und Ladetest; Zustandsfarben (Laden, Warten, Fehler …) wie in allen Modulen; Farbschema geht als Zahl an die Kachel; Systemschrift statt Poppins |
| 1.4 | 9 | 06.10.2026 | Lade-Erinnerung am Abend: Meldung an die Visualisierung und Hinweis in der Kachel, wenn das Auto ab 21 Uhr am Ort der Location Control steht, aber nicht angesteckt ist; flache Handy-Kachel (2×1) wieder mit Ring links und Werten rechts |
| 1.3 | 8 | 04.10.2026 | Prüfung nachgeschärft: Variablen werden nur bei Änderung geschrieben, Dashboard mit Darstellung „Webinhalt“ statt `~HTMLBox`, ungültige Zeichen aus der Cloud brechen die Kachel nicht mehr ab, Akzentfarbe des Symcon-Designs für die Stromgrenze-Tasten |
| 1.3 | 7 | 04.10.2026 | Symcon-9.0-Technik: `IPSModuleStrict`, Darstellungen statt Profile, Farbschema (Symcon-Design / Dunkel / Hell); Sicherheit: Prüfung aller Aktionen und Werte, sichere Einbettung der Kachel-Daten, nur HTTPS mit Zertifikatsprüfung, nur echte Bilder; Geschwindigkeit: Kachel-Updates nur bei Änderung, Bilder verkleinert und zwischengespeichert, gzip; Tests, Ladetest und MIT-Lizenz |
| 1.2 | 6 | 02.10.2026 | Tablet hochkant: Leistungsring richtet sich nach dem freien Platz |
| 1.2 | 5 | 02.10.2026 | Akkustand aktualisiert sich nach einem Neustart von Symcon wieder |
| 1.2 | 4 | 01.10.2026 | „Eigenes Auto“ oder „Gastladung“ fest in der Instanz |
| 1.2 | 2 | 01.10.2026 | Lichtstreifen der Wallbox füllt sich beim Laden von unten nach oben |
| 1.2 | 1 | 01.10.2026 | Neue Zählung; enthält alle bisherigen Erweiterungen (siehe unten) |

Bis 1.2 (Build 1) enthalten:

- Neues Akku-Symbol über dem Auto: Kapsel mit Farbverlauf (grün / gelb unter 50 % / rot unter 20 %), Blitz und Lichtreflex beim Laden; Akku-Balken in denselben Farben
- „Ladung“ zeigt sofort Werte: Session-Energie wird zusätzlich aus Gesamtzähler und Ladeleistung mitgerechnet, weil die Easee-Cloud ihren Session-Zähler nur verzögert aktualisiert
- Akku im Auto-Symbol mit Ladefortschritt, Kabel endet am Ladeanschluss, eigenes Fahrzeugbild mit einstellbarem Ladeanschluss
- Optionaler Akkustand des Fahrzeugs aus einer beliebigen Variable (Kachel, Dashboard, „Fertig bis“ mit Ziel in Prozent)
- Kompakte Handy-Ansicht für flache Kacheln
- Eigenes Hintergrundbild für die Kachel (mit Abdunkeln und Weichzeichnen)
- Energie und Kosten pro Tag mit Archiv (Tag/Woche/Monat/Jahr), laufender Kostenzähler, Strompreis im Instanz-Formular
- Kachel mit Leistungsring, Werteliste, Linien-Icons und animierter Wallbox-/Fahrzeug-Grafik
- Ladestrom-Grenze, Zeitsteuerung (Zeitfenster / Fertig bis), Monats- und Jahresstatistik
- Erste Version als Modul (Ablösung der Skriptsammlung)

## Lizenz

Dieses Modul steht unter der **MIT-Lizenz** (siehe Datei [`LICENSE`](LICENSE)).

Das Modul darf jeder kostenlos nutzen, verändern und weitergeben, auch kommerziell. Bedingung ist nur, dass der Copyright-Hinweis
und der Lizenztext in Kopien erhalten bleiben. Eine Gewährleistung gibt es nicht.

Jede Code-Datei trägt einen Lizenzkopf mit `SPDX-License-Identifier: MIT`. Wer das Modul weitergibt oder Teile davon übernimmt,
behält diesen Kopf und die Datei `LICENSE` bei.

**Hinweis:** Easee ist eine Marke der Easee AS. Dieses Modul ist kein offizielles Produkt von Easee und steht in keiner Verbindung zu Easee.
Es nutzt die öffentliche Easee-Cloud-Schnittstelle mit den Zugangsdaten des eigenen Kontos.
