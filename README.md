# Dolibarr FinTS Bank Module

**Version 1.0.0** | Automatischer Kontoabruf per FinTS/HBCI

[![Dolibarr](https://img.shields.io/badge/Dolibarr-18.0%2B-blue.svg)](https://www.dolibarr.org)
[![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://www.php.net)

---

## Features

### Kontoabruf per FinTS
- **Automatischer Abruf** - Kontoauszuege direkt von der Bank abrufen
- **TAN-Unterstuetzung** - photoTAN, pushTAN, chipTAN
- **Mehrere Konten** - Beliebig viele Bankverbindungen verwalten
- **Transaktions-Import** - Buchungen ins Dolibarr-Bankkonto uebernehmen

### Unterstuetzte Banken
- Commerzbank
- Sparkassen
- Volksbanken/Raiffeisenbanken
- Postbank
- DKB
- ING
- Und viele weitere FinTS-faehige Banken

### Transaktionsverwaltung
- **Status-Tracking** - Neu, Zugeordnet, Importiert, Ignoriert
- **Massen-Import** - Alle neuen Transaktionen auf einmal importieren
- **Ignorieren/Wiederherstellen** - Transaktionen ausblenden und wiederherstellen
- **Bank-Verknuepfung** - Direkter Link zur Dolibarr-Bankbuchung

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
# Setup → Module → FinTS Bank → Aktivieren
```

**Voraussetzungen:**
- Dolibarr 18.0+
- PHP 7.4+ mit OpenSSL, cURL, mbstring
- Composer

---

## Konfiguration

### Bankverbindung einrichten

1. **FinTS Bank → Bankverbindungen → Neu**
2. Dolibarr-Bankkonto auswaehlen (muss bereits existieren)
3. Bankdaten eingeben:
   - **BLZ** - 8-stellige Bankleitzahl
   - **FinTS-URL** - Server-Adresse der Bank
   - **Benutzername** - Online-Banking Zugangsnummer
   - **IBAN** - Fuer Kontoidentifikation

### FinTS-URLs (Beispiele)

| Bank | FinTS-URL |
|------|-----------|
| Commerzbank | `https://fints.commerzbank.com/fints30` |
| Sparkassen | `https://banking-[blz].s-fints-pt-[region].de/fints30` |
| Volksbank | Je nach Institut unterschiedlich |
| Postbank | `https://hbci.postbank.de/banking/hbci` |
| DKB | `https://banking-dkb.s-fints-pt-dkb.de/fints30` |
| ING | `https://fints.ing.de/fints/` |

---

## Verwendung

### Kontoabruf

1. **FinTS Bank → Kontoabruf**
2. Bankkonto auswaehlen
3. PIN eingeben
4. TAN eingeben (photoTAN/pushTAN)
5. Transaktionen werden automatisch importiert

### Transaktionen verwalten

1. **FinTS Bank → Transaktionen**
2. Neue Transaktionen pruefen
3. Aktionen:
   - **Importieren** (➕) - Einzelne Transaktion ins Bankkonto
   - **Alle importieren** - Alle neuen auf einmal
   - **Ignorieren** - Transaktion ausblenden
   - **Wiederherstellen** - Ignorierte zurueckholen

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

---

## Changelog

### v1.0.0 (2026-01-01)
- Erster stabiler Release
- FinTS/HBCI Kontoabruf mit TAN-Unterstuetzung
- photoTAN/pushTAN Integration
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

**Feedback?** → [GitHub Issues](https://github.com/Gerrett84/dolibarr-fintsbank/issues)
