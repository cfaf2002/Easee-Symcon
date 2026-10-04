<?php

declare(strict_types=1);

/**
 * Testsuite für das Easee-Modul: php tests/run.php  (DEBUG=1 zeigt Debug-Ausgaben)
 *
 * Copyright (c) 2026 Armin Frohwerk
 * SPDX-License-Identifier: MIT
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../EaseeWallbox/module.php';

/** Easee-Cloud wird simuliert */
class T extends EaseeWallbox
{
    public array $obs = [];
    public array $cmds = [];

    protected function HttpRequest(string $method, string $url, ?array $body, ?string $token): array
    {
        if (str_contains($url, '/accounts/login')) {
            return [200, json_encode(['accessToken' => 'T', 'refreshToken' => 'R', 'expiresIn' => 86400])];
        }
        if (str_ends_with($url, '/api/chargers')) {
            return [200, json_encode([['id' => 'EH1', 'name' => 'Garage']])];
        }
        if (str_contains($url, '/observations')) {
            $o = [];
            foreach ($this->obs as $k => $v) {
                $o[] = ['id' => $k, 'value' => $v];
            }
            return [200, json_encode(['observations' => $o])];
        }
        $this->cmds[] = preg_replace('#.*/EH1/#', '', $url) . ' ' . json_encode($body);
        return [200, ''];
    }

    public function last(): string
    {
        return end($this->cmds) ?: '-';
    }

    public function call(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($this, $method))->invoke($this, ...$args);
    }
}

function rejects(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}

echo "Grundfunktionen\n";
$m=new T(); $GLOBALS['mod']=$m; $m->Create();
$m->p['Username']='a'; $m->p['Password']='b'; $m->p['EnableSchedule']=true; $m->ApplyChanges();
check(isset($m->v['ScheduleMode']) && $m->v['TargetEnergy']==20.0, 'Zeitsteuerungs-Variablen angelegt');
check($m->timers['ScheduleTimer']===0, 'Schedule-Timer aus bei Modus Aus');

$base=[109=>2,114=>0,110=>30,120=>0,121=>0.0,124=>1000.0,48=>16,47=>16,250=>true,119=>0];
$m->obs=$base; $m->Update();
check($m->v['ChargeLimit']===16, 'Stromgrenze aus Obs 48 gelesen');
check($m->v['EnergyMonth']==0.0, 'Statistik: erster Abruf nicht gewertet');
$m->obs=[124=>1012.5]+$base; $m->Update();
check(abs($m->v['EnergyMonth']-12.5)<0.01 && abs($m->v['CostMonth']-3.75)<0.01, 'Statistik: 12,5 kWh / 3,75 € im Monat');
$m->obs=[124=>50.0]+$base; $m->Update();
check(abs($m->v['EnergyMonth']-12.5)<0.01, 'Statistik: Zählersprung ignoriert');

// Ladestrom
$m->RequestAction('ChargeLimit', 10);
check($m->last()==='settings {"dynamicChargerCurrent":10}' && $m->v['ChargeLimit']===10, 'Stromgrenze 10 A gesendet');
$m->SetChargeLimit(40); check(str_contains($m->last(),'"dynamicChargerCurrent":16'), 'Stromgrenze auf 16 A begrenzt (11 kW)');

// Zeitfenster enthält jetzt
$now=time();
$m->RequestAction('ScheduleStart', $now-3600); $m->RequestAction('ScheduleEnd', $now+3600);
$m->cmds=[]; $m->RequestAction('ScheduleMode', 1);
check($m->timers['ScheduleTimer']===60000, 'Schedule-Timer läuft');
check($m->last()==='commands/resume_charging null', 'Im Zeitfenster: resume gesendet ('.$m->v['ScheduleInfo'].')');
$m->cmds=[]; $m->RequestAction('ScheduleTick', true); check(count($m->cmds)===0, 'Kein doppelter Befehl beim nächsten Tick');
// Fenster außerhalb
$m->RequestAction('ScheduleStart', $now+3600); $m->cmds=[]; $m->RequestAction('ScheduleEnd', $now+7200);
check($m->last()==='commands/pause_charging null', 'Außerhalb: pause gesendet ('.$m->v['ScheduleInfo'].')');
// Manuell
$m->cmds=[]; $m->StartCharging(); $m->RequestAction('ScheduleTick', true);
check(count($m->cmds)===1 && $m->v['ScheduleInfo']==='Manuell übersteuert (bis zum Abstecken)', 'Manuelles Starten übersteuert');
// Abstecken setzt zurück
$m->obs=[109=>1]+$base; $m->obs[124]=50.0; $m->Update();
check($m->v['ScheduleInfo']==='Wartet auf Fahrzeug' && $m->a['ManualOverride']===0, 'Abstecken setzt Übersteuerung zurück');

// Fertig bis: 20 kWh in 1 h mit 10 A -> sofort laden
$m->obs=$base; $m->obs[124]=50.0; $m->obs[48]=10; $m->Update();
$m->RequestAction('ReadyBy', $now+3600); $m->cmds=[]; $m->RequestAction('ScheduleMode', 2);
check($m->last()==='commands/resume_charging null', 'Fertig bis knapp: sofort laden ('.$m->v['ScheduleInfo'].')');
// 5 kWh in 6 h -> später starten
$m->RequestAction('ReadyBy', $now+6*3600); $m->cmds=[]; $m->RequestAction('TargetEnergy', 5.0);
check($m->last()==='commands/pause_charging null' && str_starts_with($m->v['ScheduleInfo'],'Start um'), 'Fertig bis viel Zeit: '.$m->v['ScheduleInfo']);
// Ziel erreicht
$m->obs[121]=5.2; $m->Update();
check(str_starts_with($m->v['ScheduleInfo'],'Ziel erreicht'), 'Ziel erreicht erkannt: '.$m->v['ScheduleInfo']);

// Historie: Pause -> zweiter Ladeabschnitt zählt nur neuen Anteil
$m->RequestAction('ScheduleMode',0);
$m->obs=[109=>3,114=>16,121=>5.2]+$base; $m->Update();
$m->obs=[109=>2,114=>0,121=>8.0]+$base; $m->Update(); $m->Update();
$h=json_decode($m->GetHistory(),true);
check(count($h)===1 && abs(end($h)['energy']-2.8)<0.01, 'Historie zählt nur neuen Anteil nach Pause (2,8 kWh)');

// Tile
$tile=$m->GetVisualizationTile(); check(str_contains($tile,'handleMessage("{'), 'Kachel liefert HTML mit Startdaten');
check(is_array(json_decode($m->tile,true)), 'Kachel-Update gesendet');
$name=$m->ReadAttributeString('ChargerName'); $m->WriteAttributeString('ChargerName', "Garage \xC3\x28");
$tile=$m->GetVisualizationTile(); check(str_contains($tile,'handleMessage("{') && str_contains($tile,'Garage'), 'Ungültige Zeichen aus der Cloud brechen die Kachel nicht ab');
$m->WriteAttributeString('ChargerName', $name);
$m->p['EnableSchedule']=true; $m->v['ScheduleMode']=2; $m->v['ScheduleInfo']='Start um 03:40 (12,0 kWh bis 07:00)';
$m->WriteAttributeString('Stats', json_encode(['2026-05'=>['e'=>120,'c'=>36],'2026-06'=>['e'=>95,'c'=>28.5],'2026-07'=>['e'=>140,'c'=>42],'2026-08'=>['e'=>80,'c'=>24],'2026-09'=>['e'=>60.5,'c'=>18.2]]));


echo "Symcon 9.0: Darstellungen\n";
check($m->pres['Status']['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION
    && str_contains($m->pres['Status']['OPTIONS'], 'Lädt'), 'Status als Aufzählung mit Texten, Symbolen und Farben');
check($m->pres['ChargeLimit']['PRESENTATION'] === VARIABLE_PRESENTATION_SLIDER && $m->pres['ChargeLimit']['MAX'] == 16, 'Ladestrom-Grenze als Schieberegler 6–16 A');
check($m->pres['ChargingActive']['PRESENTATION'] === VARIABLE_PRESENTATION_SWITCH, 'Laden als Schalter');
check($m->pres['ScheduleStart']['PRESENTATION'] === VARIABLE_PRESENTATION_DATE_TIME && $m->pres['ScheduleStart']['TIME'] === 1, 'Uhrzeiten als Datum/Uhrzeit-Darstellung');
$profiles = array_filter($m->pres, fn ($p) => is_string($p));
check($profiles === [], 'Keine Variablenprofile mehr, nur Darstellungen');
$m->p['Dashboard'] = true; $m->ApplyChanges();
check(($m->pres['Dashboard']['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_WEB_CONTENT, 'Dashboard als Darstellung „Webinhalt“');
$m->p['Dashboard'] = false; $m->ApplyChanges();

echo "Sicherheit\n";
check(rejects(fn () => $m->RequestAction('Gibtsnicht', 1)), 'Unbekannte Aktion wird abgelehnt');
check(rejects(fn () => $m->RequestAction('ChargingActive', 'ja')), 'Laden: Text statt Ja/Nein wird abgelehnt');
check(rejects(fn () => $m->RequestAction('ChargeLimit', [1])), 'Stromgrenze: Liste wird abgelehnt');
$m->p['EnableSchedule'] = true; $m->ApplyChanges();
check(rejects(fn () => $m->RequestAction('ScheduleMode', 7)), 'Zeitsteuerung: Modus 7 wird abgelehnt');
$m->RequestAction('TargetEnergy', 999);
check($m->v['TargetEnergy'] == 200.0, 'Ziel-Energie wird auf 200 kWh begrenzt');
$m->RequestAction('ScheduleMode', 0);
$m->WriteAttributeString('ChargerName', '</script><img src=x onerror=alert(1)>');
$html = $m->GetVisualizationTile();
check(!str_contains($html, '</script><img') && substr_count($html, '</script>') === substr_count((string) file_get_contents(__DIR__ . '/../EaseeWallbox/module.html'), '</script>') + 1,
    'Eingeschleustes HTML kann das Kachel-Skript nicht beenden');
check(!str_contains((string) file_get_contents(__DIR__ . '/../EaseeWallbox/module.html'), '.innerHTML'), 'Kachel setzt Werte nur als Text (kein innerHTML)');

echo "Geschwindigkeit\n";
$m->Update(); $m->writes = []; $m->Update();
$rest = array_values(array_diff($m->writes, ['LastUpdate']));
check(count($rest) <= 2, 'Zweiter Abruf ohne Änderungen schreibt kaum Variablen (' . count($m->writes) . ' statt über 30): ' . implode(', ', $rest));
$m->pushes = [];
$m->call('PushTile'); $m->call('PushTile'); $m->call('PushTile');
check(count($m->pushes) <= 1, 'Unveränderte Kacheldaten werden nicht erneut gesendet');
$m->v['Power'] = 7.4; $m->call('PushTile');
check(count($m->pushes) >= 1 && json_decode((string) end($m->pushes), true)['power'] === '7,40', 'Geänderte Daten werden gesendet');
$img = imagecreatetruecolor(3200, 2000);
ob_start(); imagejpeg($img, null, 95); $jpg = (string) ob_get_clean();
$m->p['TileBackground'] = base64_encode($jpg);
$url = $m->call('ImageDataUrl', 'TileBackground');
$size = getimagesizefromstring(base64_decode(substr($url, strlen('data:image/jpeg;base64,'))));
check(str_starts_with($url, 'data:image/jpeg;base64,') && $size[0] === 1600, 'Großes Hintergrundbild wird auf 1600 px verkleinert');
check($m->call('ImageDataUrl', 'TileBackground') === $url && count(json_decode($m->a['ImageCache'], true)) === 1, 'Verkleinertes Bild wird zwischengespeichert');
$m->p['CarImage'] = base64_encode('<svg onload="alert(1)"></svg>');
check($m->call('ImageDataUrl', 'CarImage') === '', 'Keine Bilddatei (z. B. SVG/HTML) → wird nicht eingebettet');
$m->p['TileBackground'] = ''; $m->p['CarImage'] = '';

echo "Kachel-Farbschema\n";
$m->p['TileTheme'] = 2;
check($m->call('TileData')['theme'] === 'light', 'Farbschema „Hell“ wird an die Kachel gegeben');
$m->p['TileTheme'] = 0;

echo "Tageswerte und Strompreis\n";
$m->obs=$base; $m->obs[124]=50.0; $m->Update(); $m->WriteAttributeString('DayKey', date('Y-m-d'));
$e0=$m->v['EnergyToday']; $t0=$m->v['CostToday']; $c0=$m->v['CostCounter'];
$m->obs=$base; $m->obs[124]=60.0; $m->Update();   // +10 kWh
check(abs($m->v['EnergyToday']-$e0-10)<0.01 && abs($m->v['CostToday']-$t0-3.0)<0.02, 'Heute: +10 kWh / 3,00 €  ('.$m->v['EnergyToday'].' kWh, '.$m->v['CostToday'].' €)');
check(abs($m->v['CostCounter']-$c0-3.0)<0.01, 'Kostenzähler +3,00 €');
$m->WriteAttributeString('DayKey', '2000-01-01'); $m->obs[124]=62.0; $m->Update();
check(abs($m->v['EnergyToday']-2.0)<0.01, 'Tageswechsel: heute neu ab 0 (2 kWh)');
$cc=$m->v['CostCounter'];
$m->SetEnergyPrice(0.40);
check($m->v['EnergyPrice']==0.40 && $m->p['EnergyPrice']==0.40, 'Preis über Instanz gesetzt');
$m->obs[124]=67.0; $m->Update();
check(abs($m->v['CostCounter']-$cc-2.0)<0.01, 'Neuer Preis gilt ab jetzt (5 kWh x 0,40 = 2,00 €)');
// Migration: alte Instanz mit Preis in Variable
$n=new T(); $GLOBALS['mod']=$n; $n->Create(); $n->p['Username']='a'; $n->p['Password']='b';
$n->RegisterVariableFloat('EnergyPrice','x','',0); $n->v['EnergyPrice']=0.3421;
$n->ApplyChanges();
check(abs($n->p['EnergyPrice']-0.3421)<0.00001 && abs($n->v['EnergyPrice']-0.3421)<0.00001, 'Alter Preis 0,3421 ins Formular übernommen');
$GLOBALS['mod']=$m;

echo "Akkustand des Autos\n";
$GLOBALS['mod']=$m;
check(!isset($m->v['SoC']), 'Ohne Schalter keine Akku-Variable');
$GLOBALS['ext'][555]=41.6;
$m->p['EnableSoc']=true; $m->p['SocVariable']=555; $m->p['BatteryCapacity']=15.0; $m->ApplyChanges();
$m->msgs=[]; $m->ApplyChanges(); check(isset($m->msgs[555]), 'Nach Neustart wieder beim Akkustand angemeldet');
check(isset($m->v['SoC']) && $m->v['SoC']===42 && isset($m->msgs[555]), 'Akkustand 42 % übernommen, Änderungen abonniert');
check(isset($m->v['TargetSoc']) && $m->v['TargetSoc']===100, 'Ziel-Akkustand angelegt (100 %)');
$td=json_decode($m->tile,true); check($td['soc']===42, 'Kachel bekommt Akkustand');
// Fertig bis mit Prozent
$m->obs=[109=>2,114=>0,110=>10,120=>0,121=>0.0,124=>67.0,48=>16,47=>16,250=>true,119=>0]; $m->Update();
$m->RequestAction('ReadyBy', time()+10*3600); $m->RequestAction('TargetSoc', 80); $m->cmds=[]; $m->RequestAction('ScheduleMode', 2);
check(str_contains($m->v['ScheduleInfo'],'42 % → 80 %') && str_starts_with($m->v['ScheduleInfo'],'Start um'), 'Fertig bis in %: '.$m->v['ScheduleInfo']);
$GLOBALS['ext'][555]=81; $m->MessageSink(0,555,VM_UPDATE,[]);
check($m->v['SoC']===81 && str_starts_with($m->v['ScheduleInfo'],'Ziel erreicht (81 %)'), 'Ziel per Akkustand erreicht: '.$m->v['ScheduleInfo']);
echo "Gastladung\n";
$GLOBALS['ext'][555]=50; $m->MessageSink(0,555,VM_UPDATE,[]);
$m->p['VehicleMode']=1; $m->ApplyChanges();
$td=json_decode($m->tile,true);
check($td['guest']===true && $td['soc']===null && $td['progress']!==50 && $td['car']['image']==='', 'Gastladung: kein Akkustand, neutrales Auto');
check(!isset($m->v['SoC']) && !isset($m->v['TargetSoc']) && !isset($m->msgs[555]), 'Gastladung: Akku-Variablen und Abo entfernt');
$m->obs[109]=1; $m->Update(); $m->obs[109]=2; $m->Update();
check(json_decode($m->tile,true)['guest']===true, 'Gastladung bleibt nach Abstecken bestehen');
check(!str_contains($m->v['ScheduleInfo'],'%'), 'Gastladung: Fertig bis nur mit kWh-Ziel: '.$m->v['ScheduleInfo']);

$form=json_decode($m->GetConfigurationForm(),true); $fj=json_encode($form);
$vis=function($name) use (&$form){ $r=null; array_walk_recursive($form,function(){}); $stack=[$form['elements']]; while($stack){ foreach(array_pop($stack) as $i){ if(($i['name']??'')===$name) $r=$i['visible']??true; if(isset($i['items'])) $stack[]=$i['items']; } } return $r; };
check($vis('SocVariable')===false && $vis('GuestHint')===true, 'Formular: Akku-Felder bei Gastladung ausgeblendet');
$m->p['VehicleMode']=0; $m->ApplyChanges();
$form=json_decode($m->GetConfigurationForm(),true);
check($vis('SocVariable')===true && $vis('GuestHint')===false, 'Formular: eigenes Auto zeigt Akku-Felder');
$td=json_decode($m->tile,true); check($td['guest']===false && $td['soc']===50 && isset($m->v['SoC']), 'Zurück auf eigenes Auto: Akkustand wieder da');
$m->p['EnableSoc']=false; $m->ApplyChanges();
check(!isset($m->v['SoC']) && !isset($m->msgs[555]) && !isset($m->v['TargetSoc']), 'Ausschalten entfernt Variable und Abo');
echo "Ladung schätzen\n";
$e=new T(); $GLOBALS['mod']=$e; $e->Create(); $e->p['Username']='a'; $e->p['Password']='b'; $e->ApplyChanges();
$b=[109=>3,114=>16,110=>10,120=>3.5,121=>0.0,124=>13300.0,48=>32,47=>32,250=>true,119=>0];
$e->obs=$b; $e->Update();
$e->a['LastPoll']=time()-600; $e->obs=[124=>13300.4]+$b; $e->Update();
check(abs($e->v['SessionEnergy']-0.58)<0.02, 'Cloud meldet 0 kWh -> Schätzung aus Leistung (3,5 kW x 10 min = 0,58 kWh): '.$e->v['SessionEnergy']);
$e->a['LastPoll']=time()-60; $e->obs=[124=>13301.5]+$b; $e->Update();
check(abs($e->v['SessionEnergy']-1.5)<0.02, 'Gesamtzähler +1,5 kWh wird genutzt: '.$e->v['SessionEnergy']);
$e->obs=[121=>2.2,124=>13301.5]+$b; $e->Update();
check(abs($e->v['SessionEnergy']-2.2)<0.02, 'Cloud-Wert höher -> Cloud-Wert: '.$e->v['SessionEnergy']);
$e->obs=[109=>1,114=>0,120=>0,121=>0.0]+$b; $e->Update(); $e->Update();
$e->obs=[109=>2,114=>0,120=>0,121=>0.0,124=>13301.5]+$b; $e->Update();
check($e->v['SessionEnergy']==0.0, 'Nach Ab- und Einstecken wieder bei 0');
$GLOBALS['mod']=$m;

echo PHP_EOL . sprintf('%d Prüfungen bestanden, %d fehlgeschlagen.', $passed, $failed) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
