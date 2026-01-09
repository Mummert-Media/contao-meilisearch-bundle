# Contao Meilisearch Bundle

Eine schlanke Schnittstelle zwischen **Contao CMS (4.13 / 5.6 / 5.7 ready) unter PHP 8.4** und einer **selbst gehosteten Meilisearch-Instanz**.  
Das Bundle erweitert den Contao-Suchindex um strukturierte Daten und ermöglicht eine performante, moderne Volltextsuche.
Das Parsen von Dateien erfolgt über eine Apache-Tika-Instanz, welche extern bereitgestellt werden muss.

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

### Datei-Parsing

```
/vendor/bin/contao-console meilisearch:files:parse
```

### Meilisearch-Index

```
/vendor/bin/contao-console meilisearch:index
```



## Beispiel Crontab

```
0 5 * * *  /usr/bin/php8.4 /path/to/project/vendor/bin/contao-console meilisearch:files:cleanup
1 5 * * *  /usr/bin/php8.4 /path/to/project/vendor/bin/contao-console contao:crawl
10 5 * * * /usr/bin/php8.4 /path/to/project/vendor/bin/contao-console meilisearch:files:parse
20 5 * * * /usr/bin/php8.4 /path/to/project/vendor/bin/contao-console meilisearch:index
```

## Logging

```
>> var/logs/meilisearch_cron.log 2>&1
```

## Lizenz

MIT