# LG ThinQ Sync

Folgende Module beinhaltet das LG ThinQ Repository:

- __LG ThinQ Bridge__ ([Dokumentation](LG%20ThinQ%20Bridge))  
- __LG ThinQ Configurator__ ([Dokumentation](LG%20ThinQ%20Configurator))  
- __LG ThinQ Device__ ([Dokumentation](LG%20ThinQ%20Device))  

## Zweck dieses Moduls

Dieses Repository integriert LG ThinQ Geräte in IP-Symcon. Es stellt eine sichere Cloud-Anbindung über einen Personal Access Token (PAT) her, synchronisiert Gerätezustände, abonniert Ereignisse (Push/Event/MQTT) und ermöglicht die Steuerung einzelner Geräte über sauber modellierte Fähigkeiten (Capabilities). Ziel ist eine robuste, automatisch aktualisierte und benutzerfreundliche Abbildung der LG-Geräte in IP-Symcon.

## Modul-Übersicht

- **LG ThinQ Bridge** (`LG ThinQ Bridge/`)
  - Stellt die Verbindung zur LG ThinQ Cloud her (PAT-basiert).
  - Verwaltet HTTP-API und – falls konfiguriert – MQTT/Ereignis-Subscriptions inkl. Zertifikats-Handling.
  - Liefert Gerätestatus, Profile und führt Steuerbefehle aus.
  - Dient als Parent für alle Gerätemodule.

- **LG ThinQ Configurator** (`LG ThinQ Configurator/`)
  - Durchsucht die LG ThinQ Cloud nach zugeordneten Geräten.
  - Erzeugt auf Wunsch automatisch **LG ThinQ Device**-Instanzen je Gerät.
  - Vereinfacht die Erst-Inbetriebnahme und das Anlegen mehrerer Geräte.

- **LG ThinQ Device** (`LG ThinQ Device/`)
  - Repräsentiert ein einzelnes LG ThinQ Gerät in IP-Symcon.
  - Erstellt Variablen und Aktionen anhand von Capability-Definitionen.
  - Wendet moderne Präsentationen (z. B. Switch/Slider/Buttons) auf Variablen an.
  - Aktualisiert Werte automatisch über Events/Statusabfragen und ermöglicht direkte Steuerung.

## Schnellstart

1. **Bridge anlegen**: Instanz „LG ThinQ Bridge“ erstellen, PAT eintragen und Verbindung testen.
2. **Geräte finden**: „LG ThinQ Configurator“ öffnen und gewünschte Geräte auswählen/anlegen.
3. **Geräte steuern**: In den erzeugten „LG ThinQ Device“-Instanzen erscheinen Variablen und Aktionen entsprechend der Gerätefähigkeiten.

Hinweis: Für MQTT-basierte Ereignisse kann die Bridge automatisch Zertifikate anfordern und die Verbindung einrichten. Ereignisse werden dann latenzarm an die Gerätemodule weitergereicht.
	Kurze Beschreibung des Moduls.