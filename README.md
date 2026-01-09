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

---

## ⏱️ Scheduled Indexing (Cron setup)

Das Bundle stellt eigene Commands zur Verfügung, um Dateien zu bereinigen und den Meilisearch-Index neu aufzubauen.  
Für den produktiven Einsatz wird empfohlen, diese Commands regelmäßig per **System-Crontab** auszuführen.

Das Bundle nutzt **keinen eigenen Contao-Cron**, sondern System-Cronjobs.

## Verfügbare Commands

### Datei-Cleanup

```
/vendor/bin/contao-console meilisearch:files:cleanup
```

### Meilisearch-Index

```
/vendor/bin/contao-console meilisearch:index
```

## Empfohlene Reihenfolge

1. Datei-Cleanup  
   `/vendor/bin/contao-console meilisearch:files:cleanup`

2. Contao-Crawl (ca. 1 Minute später)  
   `/vendor/bin/contao-console contao:crawl`

3. Meilisearch-Index (ca. 15 Minuten später)  
   `/vendor/bin/contao-console meilisearch:index`

## Beispiel Crontab

```
0 5 * * *  /usr/bin/php8.4 /path/to/project/vendor/bin/contao-console meilisearch:files:cleanup
1 5 * * *  /usr/bin/php8.4 /path/to/project/vendor/bin/contao-console contao:crawl
15 5 * * * /usr/bin/php8.4 /path/to/project/vendor/bin/contao-console meilisearch:index
```

## Logging

```
>> var/logs/meilisearch_cron.log 2>&1
```

## Lizenz

MIT