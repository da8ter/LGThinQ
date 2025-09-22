# LG ThinQ
Beschreibung des Moduls.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Cloud-Anbindung an LG ThinQ Connect API mittels Personal Access Token (PAT)
* Abruf der Geräte-Liste und Anlage eines Gerätebaums unterhalb der Instanz
* Abfrage des Gerätestatus (zyklisches Polling mit einstellbarem Intervall)
* API-Funktionen für Status, Profile, Steuerung (Control) und Energiedaten
* Debug-Logging optional aktivierbar

### 2. Voraussetzungen

- IP-Symcon ab Version 7.1
- LG ThinQ Account und ein Personal Access Token (PAT)
  - Siehe Home Assistant Anleitung: https://www.home-assistant.io/integrations/lg_thinq/
  - PAT: https://connect-pat.lgthinq.com

### 3. Software-Installation

* Über den Module Store das 'LG ThinQ'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'LG ThinQ'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

Name              | Beschreibung
----------------- | ------------------
PAT               | Personal Access Token (Pflichtfeld)
Country Code      | Zweistelliger ISO-Code, z.B. DE, US, GB
Client ID         | Wird bei leerem Feld automatisch als UUID generiert
Polling-Intervall | Aktualisierungsintervall in Sekunden (0 = deaktiviert)
Debug             | Ausführliches Debug-Logging aktivieren

__Aktionen__:

* Verbindung testen – prüft API-Erreichbarkeit und PAT/Country-Einstellungen
* Geräte synchronisieren – lädt Geräte-Liste und legt Gerätebaum an
* Jetzt aktualisieren – aktualisiert Statuswerte aller bekannten Geräte

### 5. Statusvariablen und Profile

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name           | Typ     | Beschreibung
-------------- | ------- | ------------
Info           | String  | Geräte-Metadaten aus der LG ThinQ API
Status         | String  | Gerätestatus (Rohdaten JSON)
Letzte Aktualisierung | Integer | UnixTimestamp der letzten Status-Abfrage

#### Profile

Name   | Typ
------ | -------
       |
       |

### 6. Visualisierung

Die Funktionalität, die das Modul in der Visualisierung bietet.

### 7. PHP-Befehlsreferenz

Alle Funktionen werden mit dem Präfix `LGTQ_` bereitgestellt.

```
string LGTQ_GetDevices(int $InstanzID)
```
Gibt die Liste der Geräte als JSON-String zurück.

```
string LGTQ_GetDeviceStatus(int $InstanzID, string $DeviceID)
```
Ruft den Status eines Gerätes ab und gibt ihn als JSON-String zurück.

```
string LGTQ_GetDeviceProfile(int $InstanzID, string $DeviceID)
```
Ruft das Profil eines Gerätes ab und gibt es als JSON-String zurück.

```
bool LGTQ_ControlDevice(int $InstanzID, string $DeviceID, string $JSONPayload)
```
Sendet einen Control-Befehl an ein Gerät. Das Payload muss ein gültiges JSON im Format der LG API sein.

```
string LGTQ_GetEnergyUsage(int $InstanzID, string $DeviceID, string $Property, string $Period, string $StartDate, string $EndDate)
```
Ruft Energiedaten für ein Gerät ab. Period z.B. `DAY`, `WEEK`, `MONTH`.

Beispiel:
```
$devices = LGTQ_GetDevices(12345);
echo $devices;