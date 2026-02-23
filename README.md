# Dolibarr FinTS Bank Module

**Version 1.2.1** | Automatischer Kontoabruf per FinTS/HBCI

[![Dolibarr](https://img.shields.io/badge/Dolibarr-18.0%2B-blue.svg)](https://www.dolibarr.org)
[![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net)

> ⚠️ **Hinweis:** Die Installation und Nutzung dieses Moduls erfolgt auf eigene Verantwortung. Es wird empfohlen, vor der Installation ein Backup der Datenbank und des Dolibarr-Verzeichnisses zu erstellen.

> ℹ️ **TAN-Verfahren:** Dieses Modul unterstützt derzeit nur **photoTAN**. Andere TAN-Verfahren (pushTAN, chipTAN, etc.) werden aktuell nicht unterstützt.

---

## Features

### Kontoabruf per FinTS
- **Automatischer Abruf** - Kontoauszuege direkt von der Bank abrufen
- **TAN-Unterstuetzung** - photoTAN
- **Mehrere Konten** - Beliebig viele Bankverbindungen verwalten
- **Korrekte Vorzeichen** - Einnahmen positiv, Ausgaben negativ

### Unterstuetzte Banken
- Commerzbank
- Sparkassen
- Volksbanken/Raiffeisenbanken
- Postbank
- DKB
- ING
- Und viele weitere FinTS-faehige Banken

### Transaktionsverwaltung
- **Status-Tracking** - Neu, Importiert, Ignoriert
- **Massen-Import** - Alle neuen Transaktionen auf einmal importieren
- **Ignorieren/Wiederherstellen** - Transaktionen ausblenden und wiederherstellen
- **Bank-Verknuepfung** - Direkter Link zur Dolibarr-Bankbuchung
- **Transaktionen loeschen** - Fuer erneuten Sync

### Rechnungszuordnung
- **Automatische Zuordnung** - Rechnungen anhand Betrag und Referenz finden
- **Manuelle Zuordnung** - Dropdown mit passenden Rechnungen
- **Zahlungserstellung** - Echte Dolibarr-Zahlung wird erstellt
- **Kunden/Lieferanten-Verknuepfung** - Drittpartei wird automatisch zugeordnet
- **Kunden- und Lieferantenrechnungen** - Beide werden unterstuetzt

### Auto-Bankabgleich (NEU in v1.2)
- **Automatischer Abgleich** - Importierte Transaktionen werden automatisch als abgeglichen markiert
- **Kein Kontoauszug noetig** - Kein manueller Upload von Bankauszuegen erforderlich
- **Konfigurierbar** - Ein-/Ausschaltbar in den Modul-Einstellungen

---

## Backup-Hinweis

**Vor der Installation oder einem Update:**

```bash
# Dolibarr-Datenbank sichern
mysqldump -u root -p dolibarr > dolibarr_backup_$(date +%Y%m%d).sql

# Dolibarr-Dateien sichern
tar -czvf dolibarr_files_$(date +%Y%m%d).tar.gz /var/www/dolibarr
```

---

## Installation

```bash
# 1. Download
cd /var/www/dolibarr/htdocs/custom
git clone https://github.com/Gerrett84/dolibarr-fintsbank.git fintsbank

# 2. php-fints Library installieren
cd fintsbank
composer install

# 3. Berechtigungen
chown -R www-data:www-data /var/www/dolibarr/htdocs/custom/fintsbank

# 4. In Dolibarr aktivieren
# Setup -> Module -> FinTS Bank -> Aktivieren
```

**Voraussetzungen:**
- Dolibarr 18.0+
- PHP 7.4+ mit OpenSSL, cURL, mbstring
- Composer

---

## Konfiguration

### Bankverbindung einrichten

1. **FinTS Bank -> Bankverbindungen -> Neu**
2. Dolibarr-Bankkonto auswaehlen (muss bereits existieren)
3. Bankdaten eingeben:
   - **BLZ** - 8-stellige Bankleitzahl
   - **FinTS-URL** - Server-Adresse der Bank
   - **Benutzername** - Online-Banking Zugangsnummer
   - **IBAN** - Fuer Kontoidentifikation

### FinTS-URLs (Beispiele)

| Bank | FinTS-URL |
|------|-----------|
| Commerzbank | `https://fints.commerzbank.de/fints` |
| Sparkassen | `https://banking-[blz].s-fints-pt-[region].de/fints30` |
| Volksbank | Je nach Institut unterschiedlich |
| Postbank | `https://hbci.postbank.de/banking/hbci` |
| DKB | `https://banking-dkb.s-fints-pt-dkb.de/fints30` |
| ING | `https://fints.ing.de/fints/` |

---

## Verwendung

### Workflow

```
1. Sync      ->  2. Import      ->  3. Zuordnung
(von Bank)       (ins Bankkonto)    (mit Rechnung)
```

### Kontoabruf

1. **FinTS Bank -> Kontoabruf**
2. Bankkonto auswaehlen
3. PIN eingeben
4. TAN eingeben (photoTAN/pushTAN)
5. Transaktionen werden automatisch geladen

### Transaktionen verwalten

1. **FinTS Bank -> Transaktionen**
2. Neue Transaktionen pruefen
3. **Schritt 1 - Import:**
   - **Importieren** - Einzelne Transaktion ins Bankkonto
   - **Alle importieren** - Alle neuen auf einmal
   - **Ignorieren** - Transaktion ausblenden
4. **Schritt 2 - Zuordnung (nach Import):**
   - **Auto-Match** - Automatisch passende Rechnung finden
   - **Manuell** - Rechnung aus Dropdown waehlen
   - **Alle zuordnen** - Alle importierten automatisch zuordnen

### Rechnungszuordnung

Nach dem Import koennen Transaktionen mit Rechnungen verknuepft werden:

- **Einnahmen** (positive Betraege) -> Kundenrechnungen
- **Ausgaben** (negative Betraege) -> Lieferantenrechnungen

Bei der Zuordnung wird automatisch:
- Eine echte Dolibarr-Zahlung erstellt
- Die Rechnung als bezahlt markiert
- Der Kunde/Lieferant verknuepft
- Die Bankbuchung mit der Zahlung verbunden

---

## Sicherheit

- PINs werden **nicht gespeichert** - Eingabe bei jedem Abruf
- TAN-Verfahren nach Bankvorgabe
- Alle Verbindungen verschluesselt (HTTPS/TLS)
- Session-Daten werden nach Abschluss geloescht

---

## Troubleshooting

**Verbindungsfehler?**
- FinTS-URL korrekt?
- BLZ 8-stellig?
- Benutzername = Online-Banking Zugangsnummer

**TAN-Fehler?**
- Richtiges TAN-Verfahren aktiviert?
- photoTAN-App aktuell?
- Push-Benachrichtigung erhalten?

**Import-Fehler?**
- Dolibarr-Bankkonto mit FinTS-Konto verknuepft?
- Berechtigungen fuer Bankmodul vorhanden?

**Falsche Vorzeichen?**
- Transaktionen loeschen und neu synchronisieren
- Ab v1.1 werden Vorzeichen korrekt erkannt

---

## Changelog

### v1.2.1 (2026-02-23)
- **Menu-Fix** - Admin-Menü erscheint nur noch unter Home > Einstellungen
- Zahnrad-Icon in Modulliste für direkten Zugriff auf Einstellungen

### v1.2.0 (2026-01-28)
- **Auto-Bankabgleich** - Importierte Transaktionen automatisch als abgeglichen markieren
- **Einstellungen-Tab** - Neuer Tab fuer Modul-Einstellungen im Admin-Bereich
- Kein manueller Kontoauszugs-Upload mehr erforderlich

### v1.1.0 (2026-01-06)
- Fix: Commerzbank FinTS-URL korrigiert
- Hinweis: Installation auf eigene Verantwortung
- Hinweis: Nur photoTAN wird unterstuetzt
- Rechnungszuordnung (Kunden- und Lieferantenrechnungen)
- Zahlungserstellung bei Rechnungszuordnung
- Korrekte Vorzeichen (Einnahmen positiv, Ausgaben negativ)

### v1.0.0 (2026-01-01)
- Erster stabiler Release
- FinTS/HBCI Kontoabruf mit photoTAN
- Transaktions-Import ins Dolibarr-Bankkonto
- Massen-Import aller neuen Transaktionen
- Ignorieren/Wiederherstellen von Transaktionen
- Deutsche und englische Sprachunterstuetzung

---

## Lizenz

GPL v3 oder hoeher

---

## Autor

**Gerrett84** - [GitHub](https://github.com/Gerrett84)

---

**Feedback?** -> [GitHub Issues](https://github.com/Gerrett84/dolibarr-fintsbank/issues)
