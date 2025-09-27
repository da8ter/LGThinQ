# LG ThinQ Configurator

Der `LG ThinQ Configurator` hilft dabei, LG ThinQ Geräte aus der Cloud in IP‑Symcon zu finden und komfortabel als `LG ThinQ Device`‑Instanzen anzulegen.

## Überblick
- **Suche in der Cloud**: Listet alle Ihrem LG‑Konto zugeordneten Geräte auf.
- **Anlage per Klick**: Erstellt zu jedem ausgewählten Eintrag eine `LG ThinQ Device`‑Instanz mit korrekter `DeviceID`.
- **Mehrgeräte‑Setup**: Vereinfacht die Erstinbetriebnahme bei mehreren Geräten.
- **Eltern‑Beziehung**: Arbeitet als Kind der `LG ThinQ Bridge` und nutzt deren Cloud‑Verbindung.

## Voraussetzungen
- Eine konfigurierte `LG ThinQ Bridge`‑Instanz mit gültigem Personal Access Token (PAT) und funktionierender Verbindung zur LG ThinQ Cloud.

## Verwendung
1. **Bridge prüfen**: Stellen Sie sicher, dass die `LG ThinQ Bridge` verbunden ist (PAT eingetragen, Status „Ready“).
2. **Konfigurator öffnen**: Instanz „LG ThinQ Configurator“ öffnen.
3. **Geräte suchen**: Auf „Suchen“ bzw. die entsprechende Aktion klicken (je nach UI). Die in der Cloud gefundenen Geräte werden angezeigt.
4. **Anlegen**: Gewünschte Geräte auswählen und als `LG ThinQ Device`‑Instanzen anlegen lassen.
5. **Fertig**: Die angelegten Geräte erscheinen im Objektbaum. Variablen und Aktionen werden vom jeweiligen Device‑Modul automatisch erstellt.

## Tipps
- Nach dem Anlegen kann im jeweiligen `LG ThinQ Device` optional ein **Alias** vergeben werden.
- Falls Geräte nicht erscheinen, prüfen Sie PAT/Region in der Bridge und die Internet‑Verbindung der Symcon‑Instanz.
