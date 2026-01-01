# Contao Meilisearch Bundle

Eine schlanke Schnittstelle zwischen **Contao CMS (4.13 / 5.6 / 5.7 ready) unter PHP 8.4** und einer **selbst gehosteten Meilisearch-Instanz**.  
Das Bundle erweitert den Contao-Suchindex um strukturierte Daten und ermöglicht eine performante, moderne Volltextsuche.

---

## ✨ Features

- Integration von **Meilisearch** als externe Suchmaschine
- Indexierung von:
    - Contao-Seiten
    - Inhaltselementen
    - **PDF-Dateien**
    - **Office-Dokumenten** (DOCX, XLSX, PPTX)
- Unterstützung für:
    - Seiten-Prioritäten
    - Keywords
    - Vorschaubild
- Kompatibel mit:
    - Contao **4.13**, **5.6** und **5.7**
    - PHP **8.4**
- Entwickelt als **eigenständiges Contao-Bundle**

---

## 📦 Installation

Installation über Composer:

```bash
composer require mummertmedia/contao-meilisearch-bundle:^0.1