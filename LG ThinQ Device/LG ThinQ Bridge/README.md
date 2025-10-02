# LG ThinQ Bridge

Die `LG ThinQ Bridge` stellt die Verbindung zwischen IP‑Symcon und der LG ThinQ Cloud her. Sie authentifiziert sich über einen Personal Access Token (PAT), ruft Geräte/Status/Profile ab und leitet Steuerbefehle weiter. Optional richtet sie eine MQTT‑/Event‑Anbindung ein, um Gerätestatus nahezu in Echtzeit zu empfangen.

## Funktionsumfang
- **PAT‑basierte Cloud‑Anbindung** zur LG ThinQ API
- **Geräteverwaltung**: Abrufen der Geräteliste, Profile und Statusdaten
- **Steuerung**: Weitergabe von Befehlen (Control) an Geräte
- **Ereignisse**: MQTT/Event‑Route, Client‑Zertifikatserzeugung und automatische Subscriptions

## Voraussetzungen
- IP‑Symcon ab Version 7.1
- LG‑Konto und ein gültiger Personal Access Token (PAT)
  - PAT erstellen: https://connect-pat.lgthinq.com

## Installation
- Über den Module Store „LG ThinQ“ installieren oder via Module Control hinzufügen.

## Einrichtung (Konfiguration)
Name | Beschreibung
---- | -----------
Personal Access Token (PAT) | Pflichtfeld. Wird in der Bridge hinterlegt.
Country Code | Zweistelliger Ländercode (z. B. DE, US). Bestimmt die Region der API.
Client ID | Client‑Kennung; automatisch erzeugt.

## Aktionen (Konfig‑Seite)
- **MQTT Verbindung einrichten**: Erstellt/erneuert Client‑Zertifikate, ermittelt Broker‑Route und richtet das Event‑Routing ein. Erzeugt einen MQTT Client und Client Socket.
- **Verbindung testen**: Prüft die Erreichbarkeit der Cloud‑API mit dem hinterlegten PAT/Region.
- **Geräte synchronisieren**: Lädt die Geräteliste und synchronisiert bekannte Geräte.
- **Jetzt aktualisieren**: Führt sofortige Aktualisierungen (z. B. Status/Profile) durch.
- **Alle bekannten Geräte abonnieren**: Abonniert Ereignisse für alle bekannten Geräte.
- **Alle bekannten Geräte abmelden**: Beendet alle Abonnements.

## Zusammenspiel mit anderen Modulen
- Der **LG ThinQ Configurator** nutzt die Bridge, um Geräte zu finden und `LG ThinQ Device`‑Instanzen anzulegen.
- Jedes **LG ThinQ Device** hängt als Kind an der Bridge, erhält Status/Ereignisse und sendet Steuerbefehle über sie.

## PHP‑Befehlsreferenz (Auszug)
Alle Funktionen werden mit dem Präfix `LGTQ_` bereitgestellt (Bridge‑Instanz als Ziel):

```php
string LGTQ_GetDevices(int $InstanzID)
```
Gibt die Liste der Geräte als JSON zurück.

```php
string LGTQ_GetDeviceStatus(int $InstanzID, string $DeviceID)
```
Ruft den Status eines Gerätes ab.

```php
string LGTQ_GetDeviceProfile(int $InstanzID, string $DeviceID)
```
Ruft das Geräteprofil ab.

```php
bool LGTQ_ControlDevice(int $InstanzID, string $DeviceID, string $JSONPayload)
```
Sendet einen Steuerbefehl (Control) an das angegebene Gerät.


## Schnellstart
1. Bridge‑Instanz anlegen, **PAT** eintragen, Verbindung testen.
2. „MQTT Verbindung einrichten“ aktivieren.
3. Mit dem **LG ThinQ Configurator** Geräte suchen und als `LG ThinQ Device` anlegen.
4. In den Device‑Instanzen erscheinen Variablen und Aktionen gemäß Gerät/Capabilities.