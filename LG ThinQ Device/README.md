# LG ThinQ Device

Das Modul `LG ThinQ Device` repräsentiert ein einzelnes LG ThinQ Gerät in IP‑Symcon. Es erstellt Variablen und Aktionen auf Basis von Capability‑Definitionen, aktualisiert Zustände automatisch über Events/Statusabfragen und ermöglicht die direkte Steuerung des Geräts.

## Überblick
- Bindet sich als Kind an die `LG ThinQ Bridge` an.
- Erstellt Gerätemerkmale (Variablen) dynamisch gemäß der Gerätefähigkeiten (Capabilities).
- Weist moderne Präsentationen zu (z. B. Switch/Slider/Buttons), sofern von Ihrer IP‑Symcon‑Version unterstützt.
- Setzt Aktionen (EnableAction) für bedienbare Variablen, damit Befehle an die Bridge/Cloud gesendet werden können.

## Funktionsumfang
- Automatisches Anlegen/Anpassen von Variablen je nach Gerätemodell und -profil, bei Geräten mit Zonen, Kanälen, Fächern oder Teilgeräten (WashTower) je Zone, Kanal, Fach bzw. Teilgerät.
- Variablennamen in der Sprache von Symcon (Deutsch oder Englisch).
- Ist die Bridge beim Anlegen noch nicht bereit, wiederholt das Gerät die Einrichtung selbständig, bis Profil und Gerätetyp vorliegen.
- Interne Abbildung von Lese-/Schreibpfaden aus dem Gerätestatus/‑profil (z. B. zusammengesetzte Werte wie Minuten ↔ Stunden/Minuten).
- Anwendung von Präsentationen (z. B. Slider mit MIN/MAX/STEP, Umschalter, Tastenleisten).
- Steuerung über `RequestAction` mit Payload‑Erzeugung passend zur jeweiligen Fähigkeit (Capability).
- Event‑Verarbeitung: Push/Event‑Daten werden von der Bridge empfangen und in Variablen gespiegelt.

## Einrichtung
1. Instanz „LG ThinQ Bridge“ anlegen und PAT in der Bridge hinterlegen.
2. Mit dem „LG ThinQ Configurator“ gewünschte Geräte suchen und anlegen.
3. In der Device‑Instanz optional einen **Alias** vergeben. Die **Device ID** wird vom Konfigurator gesetzt und ist schreibgeschützt.

## Bedienung
- Die erzeugten Variablen bilden Gerätezustände und Bedienelemente ab. Änderungen an bedienbaren Variablen senden automatisch Befehle an die Bridge/Cloud.
- Über die Aktion „Update status“ kann der aktuelle Status manuell abgefragt werden.
- `LGTQD_ReapplyPresentations($id)` setzt die Darstellungen aller Variablen neu.

## Energieverbrauch
- Das Modul erkennt automatisch, ob ein Gerät die LG Energy API unterstützt.
- Falls unterstützt, werden drei Variablen angelegt:
  - **ENERGY_YESTERDAY** – Energieverbrauch gestern (Wh)
  - **ENERGY_THIS_MONTH** – Energieverbrauch aktueller Monat (Wh)
  - **ENERGY_LAST_MONTH** – Energieverbrauch letzter Monat (Wh)
- Die Werte werden automatisch alle 6 Stunden aktualisiert (LG aggregiert die Daten nur einmal täglich).
- Die Daten stammen von der LG ThinQ Energy API (`devices/energy/{id}/usage`).

## Diagnose & Support
- Im Aktionsbereich steht „Support‑Paket (ZIP) erzeugen" zur Verfügung. Das Paket enthält anonymisierte Diagnoseinformationen (z. B. letzte Profile/Status, Capability‑Zusammenfassung), um Fehlerbilder schneller zu analysieren.

## Voraussetzungen
- Eine funktionsfähige `LG ThinQ Bridge`‑Instanz mit gültigem Personal Access Token (PAT).
- Netzwerkzugriff der Symcon‑Instanz auf die LG ThinQ Cloud.
