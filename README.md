# Plagiarism Check (local) for OJS 3.3

Compares manuscripts with every published article and every earlier submission
in the same OJS site and shows editors a similarity score with a highlighted
report. It needs no paid service and handles Uzbek (Latin and Cyrillic),
Russian and English: text is normalized to one script, so a passage retyped
from Cyrillic into Latin still matches.

It does **not** search the internet or other journals. An external provider
(StrikePlagiarism, Antiplagiat, iThenticate, …) can be plugged in later. See
[External provider](#external-provider).

Tested on OJS 3.3.0.15 with PHP 7.3–8.3.

## What editors see

- **Similarity column** in the submission files grid and the review files grid,
  with a *Check plagiarism* / *Re-check* action. The score links to the report.
- **Report**: overall score, the matching articles (each linked to its
  workflow page) and the full text with matches highlighted per source. The
  reference list is shown greyed out when it is excluded.
- **Results page** in the editorial sidebar under **Statistics → Plagiarism check**
  (`index.php/<journal>/plagiarismCheck`): every checked file, most similar
  first, with a search box.

Only journal managers, section editors and site admins see results.

## When files are checked

| Event | What happens |
|---|---|
| Author completes a submission | Manuscript files are queued (setting, on by default) |
| Article is published | Galley files are queued, so they become sources for later checks (setting, on by default) |
| Editor clicks *Check plagiarism* | The file is checked immediately (about 1 second) |
| Cron / Acron | Queued files are indexed first, then compared |

Supported formats: PDF, DOCX, ODT, HTML, TXT. Scanned PDFs without a text
layer fail with a clear message.

## Installation

1. Copy this folder to `plugins/generic/plagiarismCheck`, or upload a
   `.tar.gz` of it in *Settings → Website → Plugins → Upload a new plugin*.
   Keep the `vendor/` folder: it contains the PDF reader used when
   `pdftotext` is not available.
2. Enable **Plagiarism Check (local)** in the plugin list. The database
   tables are created on enable.
3. Set up the queue. A server cron job is recommended (for example in
   Hostinger's hPanel → Advanced → Cron Jobs):

   ```
   */5 * * * * cd /path/to/ojs && php tools/runScheduledTasks.php plugins/generic/plagiarismCheck/scheduledTasks.xml
   ```

   Without a cron job, the Acron plugin can run it instead: open the Acron
   plugin and click *Reload scheduled tasks* after enabling this plugin.
4. Index and check the back catalogue once, from the OJS root:

   ```
   php -d memory_limit=1G plugins/generic/plagiarismCheck/tools/plagiarismCheck.php backfill
   ```

   With the built-in PDF reader, 710 galleys took about 4 minutes on a laptop;
   expect longer on shared hosting. If the host has no SSH access, skip it: the
   index fills up as articles are published, and editors can check any file
   by hand.

## Command line

Run from the OJS root:

```
php plugins/generic/plagiarismCheck/tools/plagiarismCheck.php <command> [--context=journalPath]

  backfill   Index all published articles, then check each one against all others
  process    Run every queued check now
  recheck    Re-run the comparison for all checked files (after changing settings)
  status     Count queued, checked and failed files
  export     Write all results to a CSV file (opens in Excel)
```

## Settings

| Setting | Default | Notes |
|---|---|---|
| Exclude the reference list | on | Recognizes headings such as *References*, *Иқтибослар/Сноски/References*, *Список литературы*, *Foydalanilgan adabiyotlar* in the second half of the text |
| Exclude quoted text | off | Text in «», “”, „“ and "" |
| Minimum match length | 8 words | Shorter matches are ignored |
| Boilerplate threshold | 5 documents | Text found in this many other documents (issue front matter, running headers, standard legal phrases) is not counted. Lower it to 3 if old issues still show front-matter matches |
| Warning threshold | 20 % | Red at or above, orange above half |
| Path to pdftotext | `pdftotext` | Used when the server allows running it; otherwise the bundled PHP reader is used |

After changing matching settings, run `recheck` to update existing scores.

## How matching works

1. Text is extracted, and legacy 8-bit Uzbek fonts (`Ìà³îëàäà` →
   `Мақолада`) are repaired.
2. Words are lowercased, Cyrillic is transliterated to Latin, and Uzbek
   apostrophes (o‘ oʻ o' o’) are unified.
3. The letters are joined without spaces and cut into overlapping
   35-character shingles, so PDFs whose words were merged during extraction
   still match.
4. One in 16 shingle hashes is stored in `plagiarism_check_fingerprints`, which
   is used to find the 50 most likely sources.
5. Those sources are compared in full. Covered words are grouped into runs,
   and runs shorter than the minimum length are dropped. Text found in many
   sources is treated as boilerplate.
6. The score is the share of words covered by at least one source. Files
   belonging to the same submission (other versions, its own galley) are
   never counted as sources.

Storage: about 50 KB of text and 1,500 index rows per article.

## External provider

The settings form has a provider selector that lists the providers installed;
none are bundled yet. To add one, implement
`classes/providers/ExternalProvider.inc.php` (submit a file, fetch the result)
and return it from `PlagiarismCheckPlugin::getExternalProviders()`. Editors
then get a *Send to …* action next to the local score, and the cron task
collects the result. The local check keeps working alongside it.

## Tests

The matching core has standalone tests (no OJS needed):

```
php tests/EngineTest.php [folder-with-pdfs]
```

## Files

```
PlagiarismCheckPlugin.inc.php     hooks, settings, routing
classes/TextExtractor             PDF/DOCX/ODT/HTML/TXT → text
classes/TextFingerprinter         normalization, tokens, shingles, bibliography detection
classes/SimilarityEngine          comparison (no OJS dependencies)
classes/PlagiarismCheckService    queue, indexing, checks, report data
classes/PlagiarismCheckDAO        database access
classes/PlagiarismCheckTask       scheduled task
controllers/PlagiarismCheckHandler  check, report and results pages
grids/                            similarity column in the file grids
tools/plagiarismCheck.php         command line tool
```
