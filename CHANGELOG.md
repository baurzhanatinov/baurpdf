# Changelog

## 2.36.1 — 2026-11-05

Проверка 2.36.0 на Windows / PHP 8.1 без poppler и qpdf. Код библиотеки не менялся — только тесты.

### Tests
- **`tests/Support/PdfText.php`:** замыкание показа текста захватывало размер шрифта и `Tc` по значению (0), поэтому все слова получали нулевую ширину и высоту (`xMax == xMin`). Проверки, сравнивавшие только `xMin`/`yMin`, это не замечали; `V223` (legend на рамке) заметил. Теперь состояние захватывается по ссылке; в `Cycle6Test` добавлена проверка реальных размеров слова.
- Девять тестов 2.19–2.36 (`V219`, `V221`, `V223`, `V228`–`V231`, `V233`, `V236`) вызывали `pdftotext` напрямую и пропускались без poppler — переведены на `TestCase::text()`, то есть на PdfText, когда poppler нет. `V221::raw()` без qpdf берёт `inflate()`.
- Windows: `testQuestionMarkInLocalFilename` снова пропускается на NTFS (при слиянии пропуск потерялся), `testSaveKeepsUmaskMode` не проверяет 0644 там, где нет umask.
- Итог на Windows без poppler/qpdf: **175 тестов, 0 падений, 20 пропусков** (было 2 падения и 27 пропусков); оставшиеся пропуски — отсутствующие шрифты (Noto CJK, Harmattan, Nanum, Noto Naskh), zxing, pdftoppm и расшифровка AES/RC4. PHPStan level max — 0 ошибок.
- Зонды циклов 5–6 на 2.36: все проходят; инструкция из 14 шагов — 0,077 с (2.19: 0,13 с — спасибо кэшу кернинга 2.26), 60 WebP под 128M — 38 МБ, EXIF 1/3/6/8 вверх, длинный URL в ячейке переносится.

## 2.36.0 — 2026-11-05

Слияние работ трёх сторонних веток от v2.17.0 (программисты **A** — «циклы 5–6», **B** — «циклы 4–18», **C** — «Cycle 21», объединение линии B с циклом 6 A). Всё перенесено 3-way поверх 2.35, проверено на нашем стенде; найденные у авторов ошибки исправлены. Спасибо всем троим; работа Бауржана над ResourceLoader (2.17.x) сохранена без ослаблений.

### Fixes (от A, подтверждены и в C)
- **`break-inside: avoid` выше страницы** раскладывался по строке на страницу (120 абзацев — 19 страниц вместо 4): откат keep-with-next теперь только если вся цепочка помещается на страницу; иначе — предупреждение `break-inside: avoid ignored for N block(s) taller than a page`.
- **`&shy;`** больше не печатается видимым дефисом, `&#8203;` (ZWSP) — точка переноса без дефиса.
- **Тема — отдельный уровень каскада** (между UA и автором): любое авторское правило перебивает тему независимо от специфичности. Классы темы только с префиксом `bp-*` (`bp-num`, `bp-row`, `bp-card`, `bp-badge`, `bp-muted`, `bp-up/down`, `bp-bold`, `bp-text-center/right`); непрефиксованные `.row/.card/.num…` больше не утекают в пользовательскую разметку. `BaurPDF::table()`/`tableHtml()`, шаблоны и примеры переведены.
- **Строчные `<img>`**: рамка, фон, отступы и `border-radius` (клип пикселей по скруглению).
- **`max-height` + `overflow: hidden`** обрезает содержимое (раньше работал только `height`).
- **`maxPages()` останавливает раскладку**, а не только печать: 20 000 абзацев при `maxPages(5)` — 1,34 с / 75 МБ → 0,04 с / 17 МБ.
- **Широкие таблицы**: перед ужатием шрифта длинные слова (URL, идентификаторы ≥ 13 знаков) переносятся по символам; `overflow-wrap: anywhere` / `word-break: break-all` уменьшают min-content; `overflow-wrap` наследуется.
- **ResourceLoader на Windows**: `C:\…`, `file:///C:/…`, сравнение allowlist с разделителем ОС. **Наше дополнение:** UNC/сетевые пути (`\\host\share`, `//host/…`, `file:////host/…`) отклоняются до `realpath()` — иначе на Windows возможна утечка NTLM на чужой хост.
- **Память на картинках**: сырые пиксели сжимаются при регистрации; WebP без лишней SMask; предупреждение `No glyph for U+XXXX in any font`.

### Features
- **EXIF Orientation 1–8** у JPEG матрицей `cm` без перекодирования, размер на экране с обменом сторон для 5–8 (A: 3/6/8, C: 1–8). **Исправлено у C:** 5 и 7 были перепутаны (транспонирование ↔ антитранспонирование); пережатие через GD (`imageDpi`) поворачивало только 3/6/8 — теперь все 2–8 (поворот + зеркало).
- **`->kerning(false)`** (A; у нас кернинг GPOS/kern уже был — подключён только флаг, собственная реализация A через `TJ` по таблице `kern` не нужна).
- **CJK-перенос** (`Text\CjkLineBreak`, от B/C): точки переноса между иероглифами без пробелов, запрет переноса перед закрывающей пунктуацией и после открывающих скобок, `word-break: keep-all`. Было 3–4 нарушения запретов на абзац, стало 0.

### API safety (от B/C)
- **Устаревший кэш рендера**: смена любой опции после `toString()`/`save()` (`tagged()`, `landscape()`, `css()`, `theme()`, `baseDir()` и т. д.) теперь даёт новый рендер, а не прежний документ.
- Повторный рендер `streamTable()` с уже прочитанным генератором/`NoRewindIterator` бросает `BaurPdfException`, а не молча выводит пустую таблицу (массивы рендерятся повторно).

### Tests
- От A: `Cycle5Test`, `Cycle6Test`, `Yii2ComponentTest`, `tests/Support/PdfText.php` (извлечение текста на чистом PHP), кроссплатформенные помощники `TestCase` (`hasTool`/`requireTool`). Исправлен тест A: `'file:///' . $path` на POSIX давал `file:////tmp/…` (UNC).
- Новый `V236FeaturesTest`: EXIF 1–8 по растру, GD-пережатие 2/4/5/7, свежий рендер после смены опций, одноразовый генератор, `kerning(false)` = `font-kerning: none`, CJK-запреты.
- Итог: 175 тестов, 2750 проверок (было 146 / 2483); PHPStan level max — 0 ошибок.

### Не принято (и почему)
- **B/C `Text\Utf8` вместо mbstring**: `ext-mbstring` у нас обязательна; замена ради окружения авторов, где её не было, — лишний код и риск скорости.
- **B/C спул страниц во временные файлы, `saveStreamed()`**: на 20 000 строк C — 4,93 с / 264 МБ против наших 5,15 с / 263 МБ, т. е. выигрыша по памяти нет, а запись на диск и 367 изменённых строк `Writer` — риск; не принято.
- **Собственный кернинг A** (`encodeTextOp`, `kern` в `TrueTypeFont`): дублирует наш GPOS+kern из 2.2x.
- **Ослабленная проверка data:/HTTP у A** (нестрогий base64, без проверки статуса HTTP): оставлены наши строгие проверки.
- CI-файлы, отчёты прогонов (`RUN_REPORT_*`, `RUN_TESTS_*.json`, `verify_cycle18.py`, `patch.py`, `smoke.php`) — служебные артефакты авторов. B и C в отчётах сами указывают, что PHPUnit/PHPStan не запускали; на нашем стенде у B 1 падение и 13 ошибок PHPStan, у C 1 падение и 15 ошибок.

### Migration
- Замените классы темы без префикса на `bp-*` (`class="num"` → `class="bp-num"` и т. д.) или объявите их в своём `<style>`.

## 2.35.0 — 2026-11-05

### Arabic
- GPOS mark-to-mark (`mkmk`, lookup type 6): a mark following an anchored mark is attached to it (shadda + fatha/kasra stack correctly instead of overlapping).

## 2.34.0 — 2026-11-05

### Arabic
- GPOS mark-to-base now also applies the vertical anchor offset: the mark is drawn with a text rise (`Ts`) between TJ fragments and the rise is reset right after, so baselines and line widths are unchanged.

## 2.33.0 — 2026-11-05

### Fidelity
- Greedy line breaking accepts a word that overflows the line by less than `LineBreaker::FIT_SLACK` (0.5 pt), matching Chromium's behaviour on the comparison corpus (our word widths come out slightly wider). rw-annual 0.995 → 1.000, rw-cv 0.997 → 1.000, rw-contract 0.999 → 1.000; other documents unchanged. CSS coverage still 50/50.
- Newsletter (0.888) is not affected: its mismatch is in the float-adjacent line placement, not the fit test.

## 2.32.0 — 2026-11-05

### Arabic
- GPOS mark-to-base (`mark` feature, lookup type 4, incl. Extension lookups): harakat are shifted horizontally to the base glyph's anchor via TJ adjustments, and the pen is restored, so line widths are unchanged. Applied only when a run contains Arabic marks and the font has GPOS.
- Known limits: vertical anchor offsets, mark-to-mark (stacked shadda + fatha) and mark-to-ligature are not applied yet.

## 2.31.0 — 2026-11-05

### Performance
- CFF (CID-keyed) subsets keep only used glyphs; the charset maps new GID → original CID, so content streams and `/W` are unchanged. A short CJK line: 246 KB → 100 KB.
- CharStrings INDEX is read lazily (only used glyphs are copied): CFF subset of Noto CJK 75 → 41 ms, peak memory −20 MB.
- Unused FDs drop their local Subrs.
- `fontDir`: per-process cmap block index (one entry per TTC collection, keyed by size/mtime) and cached file list; fonts without the needed 256-codepoint block are not loaded. Arabic presentation forms are looked up by the base letter's block; invisible format characters (bidi marks, ZW*, BOM, soft hyphen) never trigger a scan. Mixed-script fontDir render: 396 ms / 74 MB / 246 KB → 110 ms / 29 MB / 15 KB (without CJK text), 344 ms / 54 MB / 100 KB with CJK.

## 2.30.0 — 2026-11-05

### Features
- **Streaming: content after the table.** With `streamTable()`, elements after the streamed table (totals, notes, a signature block) used to be dropped with a warning. They are now laid out right after the last row, page breaks included, and memory still stays flat.
  - Siblings of the table keep its container's box. Elements further out span the full page width.
  - Text nodes get their own block.
  - `maxPages` is still honoured.

### Tests
- `tests/V230FeaturesTest.php` (2 tests): 300 rows over several pages followed by two trailing blocks, and trailing content starting below the last row.
- The V216 test now expects the trailing paragraph after the rows instead of the "ignored" warning.

## 2.29.0 — 2026-11-05

### Fidelity
- **Justified text now breaks like Chromium.** `text-align: justify` used Knuth–Plass, with spaces allowed to shrink by up to 35%. That packed more words per line than browsers do, and on long documents the drift added up to whole paragraphs. Breaking is now greedy, as in Chromium, with the spaces stretched afterwards. Knuth–Plass is still available internally as `LineBreaker::$optimal`.
- **Kerning across spaces.** Chromium measures a text run as a whole, so pairs such as "т␣", "ь␣" and "␣А" (PT Sans has them) change the line width. BaurPDF measured words and spaces separately. Pairs between a word's last letter and the space, and between the space and the next word's first letter, are now added to the space width when both words use the same font and size. This doesn't apply to RTL text or with `font-kerning:none`.
- Scorecard visual F1 vs Chromium: `rw-contract` 0.914 → **0.999**, `text` 0.963 → **1.000**, `rw-letter` 0.986 → 0.991. No document regressed.

### Tests
- `tests/V229FeaturesTest.php` (2 tests).

## 2.28.0 — 2026-11-05

### Features
- **Arabic shaping through GSUB.** Some fonts don't map Unicode Presentation Forms (U+FB50–FEFF) and get the contextual forms through GSUB instead. Harmattan, Mada and Rubik Arabic are examples. For those fonts BaurPDF now applies the `isol`/`fina`/`init`/`medi` single substitutions to the base letters at load time, and also the 1→1 entries of Multiple substitutions and Extension lookups. Lam-alef comes from the `rlig`/`liga` ligatures, using the lam init/medi + alef fina forms or the base glyphs. The results are added to the font's cmap under the presentation-form codepoints, so `Bidi::shape`, widths, kerning and ToUnicode work unchanged. Text still copies out as base letters.
  - Harmattan used to render isolated letters only (or nothing). It now renders fully joined text.
  - Fonts that already map the presentation forms (DejaVu, Noto Sans Arabic) are untouched.
  - A broken GSUB is ignored.
  - About 1–3 ms per Arabic font at load.
- Not yet: contextual (type 5/6) substitutions and GPOS mark-to-base positioning. Harakat keep the font's default offsets.

### Tests
- `tests/V228FeaturesTest.php` (3 tests).

## 2.27.0 — 2026-11-05

### Features
- **OpenType/CFF font embedding** (`.otf`, CID-keyed Noto Sans/Serif CJK `.ttc`), via `->font()`, `->fallbackFont()`, `@font-face` and `->fontDir()`. New `Font\CffSubsetter` embeds fonts as `FontFile3 /CIDFontType0C` (`CIDFontType0`):
  - Glyph ids are preserved. Unused CharStrings become `endchar`.
  - Only the global and local subroutines that the used glyphs actually reach are kept; the rest become `return`. This is found with a Type 2 charstring walk that counts stems for `hintmask`.
  - The charset of CID-keyed fonts is rewritten to identity, so CID = GID with `Identity-H`.
  - A 1 500-ideograph page renders pixel-identical (poppler, 50 dpi) to the same page with every subroutine kept, at 0.68 MB instead of 1.6 MB. A short CJK line weighs about 230 KB instead of the 19 MB collection.
  - A malformed CFF table never crashes the render. PHP warnings become an exception, and the CFF table is then embedded whole.
- **`fontDir` ranking:**
  - Decorative and display families now go last: Dela Gothic One, Diphylleia, `*Display`, script, hand and brush faces, emoji and symbol fonts, and similar.
  - `.otf`/`.otc` files are scanned too.
  - Every face of a collection is a candidate, and Noto CJK tries SC (index 2) first, as Chromium does.
  - The bold sibling of a collection face keeps its index.
  - Result: `fontDir('/usr/share/fonts')` now picks Noto Sans CJK SC (Regular/Bold) for Chinese, Japanese and Korean instead of Dela Gothic One and Gothic A1.

### Tests
- `tests/V227FeaturesTest.php` (4 tests): Noto CJK collection, an `.otf` through `@font-face`, `fontDir` ranking and the symlink allowlist, and a corrupt CFF. The V220 test that expected "CFF not supported" was updated.

## 2.26.0 — 2026-11-05

### Performance
- **Kerning regression vs 2.13 fixed.** Profiling against worktrees of 2.13–2.24 (median of 40 warm renders of `rw-dashboard`) showed the only step change at 2.21: each render re-parsed the GPOS/`kern` tables of every font (~1–2 ms per face). Parsed kerning is now cached per process (keyed by path + the CRC of the GPOS/kern table bytes), and `hasKerning()` is a cheap table check, with parsing deferred to the first real pair lookup. Warm dashboard render: 13.1 ms → 10.8 ms (2.13: 10.5 ms). The full 23-document corpus is now 14% faster than 2.13 overall (hyphenation default + this fix).

### Fixes
- `LineBreaker::knuthPlass` no longer emits "Undefined array key w" warnings for width-less items (seen on `rw-letter`).
- CHANGELOG: removed duplicated version headings for 2.13–2.25.

### Tests
- `tests/V226FeaturesTest.php` (3 tests).

## 2.25.0 — 2026-11-05

### Features
- **Automatic font fallback from directories (opt-in):** `->fontDir('/usr/share/fonts', …)` and `Options::$fontDirs`. When a glyph is missing from the main font and from the explicit fallback chain, BaurPDF looks for it in the TrueType files (`.ttf`/`.ttc`, `glyf` only) under these directories and adds the first match to the fallback chain. The matching bold face is used if one exists.
  - Search order: Noto Sans, then DejaVu Sans, then other Noto fonts, then other sans fonts, then everything else. Regular faces come before other weights.
- **Security:**
  - Each directory works as an allowlist: files reached through symlinks that point outside it are ignored.
  - Nothing is searched unless you enable it.
  - At most 4000 files are listed and at most 300 fonts are loaded per render; past that limit you get a warning.
  - A covered codepoint block is remembered for each font, so later misses don't load the same fonts again.
- Example: Latin, Chinese, Japanese, Korean, Arabic, Greek and Thai with `fontDir('/usr/share/fonts')` renders in about 0.3 s with a 19 MB peak.

### Tests
- `tests/V225FeaturesTest.php` (3 tests): a glyph found in the directory, the symlink allowlist, and a missing directory.

## 2.24.0 — 2026-11-05

### Security & robustness (contributed by Baur, reviewed and merged)

**ResourceLoader**
- Data URIs: strict canonical base64 and well-formed percent escapes are now required.
- Remote fetches: non-2xx responses are rejected (redirects are already off), and a response over the size limit is detected by reading one extra byte.
- Review fix: the canonical check now runs after the size limit, with a single decode. The submitted version decoded the payload before checking its size.
- The HTTP status is read with `http_get_last_response_headers()` on PHP 8.4+.

**`Document::save()`**
- Writes to a unique temp file (`tempnam` + `LOCK_EX`), checks that the whole file was written, and always cleans up.
- Review fix: the saved file keeps the usual umask-based mode (0644) instead of tempnam's 0600.

**`Document::disposition()`**
- Only `inline` and `attachment` are accepted, which stops header injection through the type argument. Control characters in filenames are replaced.
- Review fix: the type is matched case-insensitively.

**`Document::pageCount()`**
- Review fix (this affected both the old and the submitted code): the page count of cached or loaded PDF bytes was 0, because the page tree lives in compressed object streams. Those streams are now inflated, and the count comes from the root `/Pages`.

**`BaurPDF::tableHtml()`**
- Columns follow the first row's order even when later associative rows use a different key order.
- Every row has the same number of cells: missing cells are padded and extra ones are dropped.
- `Stringable` values are supported, invalid UTF-8 is replaced, and nested values are rendered empty.

**Other changes**
- `fromFile()` reports read errors.
- `streamTable()` chunks are capped at 2000 rows and 1 MB of HTML.
- Streamed cells accept `Stringable` values.
- Table geometry stays finite when the spacing is wider than the table, and columns are never zero-width.

### Tests
- `tests/V224FeaturesTest.php` (4 tests).
- Baur's dependency-free checks are in `tests/standalone/cycle5–13.php`; run them with `php tests/standalone/cycleN.php`.

## 2.23.0 — 2026-11-05

### Features
- **`<legend>` on the fieldset border:** the first child `<legend>` of a block `<fieldset>` is drawn on the top border, the same way browsers do it. The border runs through the middle of the legend and has a gap under it, the content starts below the legend, and the fieldset background starts at the border line. A legend that is floated, absolutely positioned, or not the first child is laid out as a normal block.
- **UA defaults matching Chromium:** `fieldset { margin: 0 2px; padding: .35em .75em .625em; border: 2px groove #c0c0c0 }`, `legend { padding: 0 2px }`, and form controls now use `color: #000` instead of inheriting the text colour.
- Fidelity against Chromium: the form document's F1 goes from 0.957 to 0.994. The other documents and all 50 feature checks are unchanged.

### Security
- **ResourceLoader hardening (contributed by Baur):**
  - control characters and unknown URI schemes are rejected;
  - `file://` URLs must be local (empty host or `localhost`);
  - NUL bytes are blocked;
  - size limits are enforced before data URIs are decoded, and local files are read with a bounded read;
  - base64 data URIs are validated strictly;
  - remote fetches no longer follow redirects, and URLs carrying credentials are refused;
  - the cache now holds only data URIs and is capped at 128 entries / 8 MB.
- Behaviour changes from the hardening:
  - base64 data URIs that contain whitespace or line breaks are now rejected;
  - `?query` suffixes on local paths are no longer stripped;
  - remote images behind redirects now fail with a warning.
- `tests/ResourceLoaderSecurityTest.php`.

### Tests
- `tests/V223FeaturesTest.php` (2 tests): the legend position, and a legend that is not the first child.

## 2.22.0 — 2026-11-05

### Changed (behaviour)
- **`hyphens` now defaults to `manual` everywhere, as in CSS and Chromium.** Before this release, justified text was hyphenated by dictionary automatically. To get that back, add `hyphens:auto` (with `lang`), for example `p { text-align: justify; hyphens: auto }`. Words longer than the line are still split, so nothing overflows.
- Fidelity against Chromium: contract F1 0.78 → 0.91, letter 0.97 → 0.99, text 0.94 → 0.96 with the page count now matching (16 = 16), and newsletter now matches 2 = 2 pages with text Jaccard 0.55 → 1.0.

### Tests
- `tests/V222FeaturesTest.php` (2 tests): no hyphens by default in justified text, and `hyphens:auto` still hyphenates.

## 2.21.0 — 2026-11-05

### Features
- **Kerning:** pair kerning is on by default. BaurPDF reads the GPOS PairPos lookups (formats 1 and 2, including Extension lookups) of the `kern` feature, and falls back to the legacy `kern` table (format 0). Kerning is applied both to line-breaking widths and to the output: kerned runs are written as `TJ` arrays, and unkerned runs still use `Tj`. In a PT Sans test line the measured width matches Chromium to 0.01pt, with and without kerning.
- **`font-kerning: none`** (inherited) turns kerning off for an element.
- A broken GPOS/kern table disables kerning for that font. It never throws.

### Notes
- Line breaks can shift slightly compared with 2.20, because kerned text is narrower.

### Tests
- `tests/V221FeaturesTest.php` (2 tests): kern pair lookup, a narrower kerned line, the opt-out, and text extraction.

## 2.20.0 — 2026-11-05

### Features
- **TrueType Collections (`.ttc`/`.otc` with glyf outlines):** fonts load from collections, `'file.ttc#N'` picks face N (default 0), and subsetting works because collection table offsets are absolute. Works in `->font()`, `->fallbackFont()` and `Options::$fonts`.
- **Clear font errors:** `TrueTypeFont::probe()` checks a font without loading it. CFF-based OpenType (`OTTO`: `.otf`, Noto CJK `.ttc`) now gives "CFF-based OpenType fonts are not supported … use the TTF build or convert with fontTools (otf2ttf)" instead of a generic format error. An index out of range in a collection is reported the same way. The document still renders with the default font and never throws.

### Docs
- Fixed the wording of the RTL list marker note in the 2.19.0 entry.

### Tests
- `tests/V220FeaturesTest.php` (2 tests): a TTC built at test time from PT Sans and DejaVu Sans Bold (face selection, bold detection, embedding, text extraction), plus the OTF warning.

## 2.19.0 — 2026-11-05

### Features
- **RTL tables:** in `direction: rtl` / `dir="rtl"` tables, columns run right to left: the first column is on the right, and `colspan` cells are mirrored as well. Row backgrounds and borders follow.
- **RTL lists:** in RTL, markers sit to the right of the item, in the inline-start padding. The UA stylesheet moves the 40px list indent to `padding-right` under `[dir=rtl]`. Numbered markers put the suffix first, as Chromium does (`1.` → `.1`, `a)` → `)a`).
- README limitations updated: floats and flex/grid are still not mirrored in RTL.

### Tests
- `tests/V219FeaturesTest.php` (2 tests): mirrored columns with colspan, LTR table untouched, marker side in RTL vs LTR.

## 2.18.0 — 2026-11-05

### Fidelity
- **Bottom margins collapse through the parent:** the last child's bottom margin now collapses with its parent's bottom margin (CSS 2.1 §8.3.1). Collapsing is blocked by bottom padding or border, `height`/`min-height`, or `overflow`. This fixes the extra gap after nested lists and after the last paragraph in a `div`.
  - rw-faq: visual F1 0.753 → 1.0.
- **Strut for lines with replaced elements:** a line that holds only an image, SVG or inline-block now keeps the font's ascent/descent (the root inline box strut, as in standards-mode browsers). Content below an inline image therefore starts after the descender gap, as in Chromium.
  - rw-catalogue: visual F1 0.925 → 1.0.
- Other compare documents and all 50 CSS feature checks are unchanged.

### Tests
- `tests/V218FeaturesTest.php` (2 tests). The margin-collapse test fails on 2.17.

## 2.17.0 — 2026-11-05

### Features
- **Image downscaling:** `->imageDpi(150)` (option `imageDpi`, off by default) resamples images that are larger than needed for their size on the page.
  - Uses GD bicubic resampling. JPEGs are re-encoded at `jpegQuality` (85 by default); other formats become PNG and keep their alpha.
  - Applied only when the image is at least 25% bigger than the target, and only when the result is smaller.
  - The same image at the same size is resampled once. The output is deterministic.
  - Example: a 2400×1600 JPEG shown 6 cm wide goes from 872 KB to 42 KB at 150 dpi.
- **Palette PNG passthrough:** indexed PNGs (1/2/4/8-bit, no tRNS) and 1/2/4-bit grayscale PNGs are embedded as-is (`/Indexed` colour space plus the PNG predictor). Before, they were decoded to 24-bit RGB, which made them about 3× larger and slower to embed.
- JPEG passthrough (DCTDecode) and the predictor passthrough for 8-bit RGB/gray PNGs were already in place and are unchanged.

### Tests
- `tests/V217FeaturesTest.php` (3 tests): Indexed passthrough, downscale size/dedupe/determinism with no upscaling, and garbage input never crashes.

## 2.16.0 — 2026-11-05

### Features
- **Streaming tables:** `BaurPDF::streamTable(iterable $rows, array $columns = [], ?callable $rowHtml = null, int $chunk = 400)`.
  - Rows are pulled from any iterator (generator, `PDOStatement`, Yii2 `each()`) in chunks. Each chunk is laid out with the same column geometry. Finished pages are painted and freed straight away, so only the last page's atoms stay in memory.
  - 50,000 rows × 4 columns: 1,191 pages, 10 s, 87 MB peak under `memory_limit=128M`. The regular renderer needs about 214 MB for 20,000 rows. Run `php tools/bench/stream.php 50000` to reproduce.
  - Uses `<table data-bp-stream>` from the HTML (its `<thead>` repeats on every page), or builds one at the end of the body from `$columns`. Array cells are HTML-escaped; strings are inserted as `<tr>` markup.
  - `{pages}`/`{nbpg}` still work: headers, footers and watermarks are now painted after all pages are known (output for regular documents is unchanged).
- `Paginator::paginate()` accepts an initial shift and page-0 headers and reports atom placement (used for incremental pagination).
- README: streaming section and Yii2 recipes (ActiveRecord `each()` + `streamTable`, fallback fonts, RTL).
- Compare harness: DejaVu Sans Mono registered for monospace (rw-receipt F1 0.866 → 1.0; harness only).

### Limitations
- The streamed table must be the last element: content after it is dropped with a warning. Columns are `table-layout: fixed`. No TOC, no `rowspan` across chunk boundaries, and the `<table>` element's own border/background is drawn only around the first chunk (cell borders work).

### Tests
- `tests/V216FeaturesTest.php` (3 tests): row continuity across 1,200 rows/100-row chunks, header repeated on each page, `{nbpg}`, escaping, custom row HTML, and a flat-memory check (8× rows < 3× memory).

## 2.15.0 — 2026-11-05

### Features
- **Arabic shaping:** letters take their contextual form (isolated, final, initial or medial) through Unicode Presentation Forms-A/B, including lam-alef ligatures and the Persian letters پ چ ژ ک گ ی. Diacritics (harakat) don't break joining. When the font has no glyph for a form, the base letter is used. In ToUnicode the forms map back to the base letters, so copy and search return normal Arabic text.
- **RTL / bidi:** `dir="rtl|ltr|auto"` and CSS `direction` (inherited).
  - Words are reordered within each line by a simplified UAX #9 L2: Latin words and numbers keep their order inside an RTL paragraph, and a run of Arabic words inside an LTR paragraph is reversed.
  - Brackets are mirrored, and punctuation at the edges of a word takes the paragraph direction (`1.` → `.1`).
  - `text-align: start/end` now take the direction into account, and RTL paragraphs are right-aligned by default.
- `line-height: normal` now honours tall fonts (ascent+descent+gap up to 3 em; before it fell back to 1.2 above 2 em). Noto Sans Arabic now gets the same line height as in Chromium.
- `rw-arabic`: visual F1 0.254 → 0.998.
  - Text Jaccard is 0.326 only because Chromium's own PDF text layer splits Arabic words ("هذ ا ن ص"). BaurPDF extracts clean logical text.

### Limitations
- No GSUB/GPOS: diacritics are not positioned.
- Tables, list markers and floats are not mirrored in RTL.

### Tests
- `tests/V215FeaturesTest.php` (4 tests).

## 2.14.0 — 2026-11-05

### Features
- **Font fallback chains.** A character missing from the primary font is now looked up per character in a chain:
  1. the other families in the `font-family` list (CSS Fonts §5);
  2. the new explicit `->fallbackFont($regular, $bold = null)` fonts (repeatable);
  3. DejaVu Sans.
  - Bold runs pick the bold face of the fallback font. `TrueTypeFont::$bold` is detected from `macStyle` / `usWeightClass`.
  - A missing or non-TrueType fallback font produces a warning instead of an error.
- The comparison harness (`tools/compare/render.php`) gives BaurPDF the same system CJK TTFs that Chromium finds through fontconfig. `rw-cjk`: visual F1 0.807 → 0.962, text Jaccard 0.556 → 1.0.

### Limitations
- Only TrueType fonts with `glyf` are supported (no CFF/OTF/TTC).

### Tests
- `tests/V214FeaturesTest.php` (3 tests).

## 2.13.0 — 2026-11-05

### Fixes
- **Cascade origin:** rules from the built-in UA stylesheet now always lose to author rules, whatever their specificity (CSS Cascade §6.1). Before, UA `ol ol{margin:0}` beat author `ol{margin:…}`.
- **Presentational hints** (`width`, `align`, `bgcolor`, …) now override UA rules and are still overridden by author CSS.
- **`type` attribute on `ol`/`ul`/`li`** (`1 a A i I`, `disc circle square`) is supported.
- `rw-faq`: text Jaccard rose from 0.897 to 1.0 and visual F1 from 0.746 to 0.753. All 50 feature checks still pass, and example page counts are unchanged.

### Tests
- `tests/V213FeaturesTest.php` (3 tests).

## 2.12.0 — 2026-11-05

### Fixes
- **Fixed / 100%-wide tables whose columns have no content width:** the leftover width was added to each column twice, so such tables overflowed the page at double width. It is now added once.
- **`height` on a table cell** is now treated as content-box, as browsers do, so padding and borders are added on top. `box-sizing:border-box` still works.
- The `rw-timetable` document's visual F1 rose from 0.641 to 0.998.

### Tests
- `tests/V212FeaturesTest.php` (2 tests).

## 2.11.0 — 2026-11-05

### Fixes
- **Form controls laid out as blocks** (as `display:block` elements or as flex items) are now drawn as controls, with their height, border and AcroForm field. Before, a blockified `<input>` was drawn as an empty block about 2pt tall with no field. The `rw-form` document's visual F1 rose from 0.611 to 0.957.

### Tests
- `tests/V211FeaturesTest.php` (2 tests).

## 2.10.0 — 2026-11-05

### Fixes
- **Percentage `height` / `min-height`** now resolve against the containing block's definite height (CSS 2.1 §10.5). When the parent's height is `auto`, they compute to `auto`. Before, they resolved against the page height, so a `height:100%` bar inside a 14pt row became a full page. The `rw-dashboard` document dropped from 13 pages to 1 (Chromium: 1), and its visual F1 rose from 0.177 to 1.0.
- Correction to the 2.9.0 notes: the dashboard's landscape `@page` size was honoured. The real cause was the percentage height above.

### Tests
- `tests/V210FeaturesTest.php` (2 tests).

## 2.9.0 — 2026-11-05

### Tooling (dev only)
- The comparison suite grows from 6 to **23 documents**. `tools/compare/gen_docs.py` deterministically generates 17 real-world layouts: a Bootstrap-like landing page, a pricing table, a long contract, a KPI dashboard, a CV, a business letter, an 80 mm receipt, a timetable with rowspan, a two-column newsletter with floats, a catalogue with inline SVG, a nested FAQ, an application form, a code listing, an annual report, and Arabic RTL, CJK and emoji documents.
- New `scorecard.py --docs REGEX` runs only the matching documents, as a quick check that writes no report.
- Baseline before the fixes (visual F1 / pages, BaurPDF vs Chromium):

  | Group | Documents |
  |---|---|
  | Good (F1 ≥ 0.97) | bootstrap 1.0, code 1.0, cv 0.997, pricing 0.996, annual 0.995, emoji 0.992, letter 0.973 |
  | Weak | catalogue 0.925, newsletter 0.899 (1 vs 2 pages), receipt 0.866, cjk 0.807, contract 0.78, faq 0.746, timetable 0.641, form 0.611 |
  | Broken | dashboard 0.177 (13 pages vs 1, landscape `@page` size ignored), arabic 0.254 (no RTL/shaping) |

## 2.8.1 — 2026-11-05

### Docs
- Full scorecard rerun (median of 3 runs) against Chrome headless. Results: 2.8× faster as a cold process and 14.0× faster in-process; 45.6× less RSS and 13.0× less PSS; files 1.96× smaller; visual fidelity 0.988; text Jaccard 1.000; CSS coverage 50/50. Page counts match on 5 of 6 documents. The exception is text: 15 pages here vs 16 in Chromium, because BaurPDF hyphenates justified text by default.
- No code changes.

## 2.8.0 — 2026-11-05

### CSS
- **`widows` / `orphans`** (inherited, default 2) are now honoured at page breaks and at column breaks in multi-column layout. A paragraph no longer leaves a single line at the bottom of a page or column, or carries one alone to the next. The rule gives way when the paragraph would otherwise leave its page or column empty. In one scorecard run, the multi-column feature check rose from 0.833 to 1.0. Mean fidelity stays at 0.988; CSS coverage stays at 50/50.

### Tests
- `tests/V28FeaturesTest.php` (3 tests). The suite is now 69 tests and 1219 assertions.

## 2.7.0 — 2026-11-05

### Fixes
- **`border-collapse: collapse`, shared borders between rows:** a shared border is now resolved as the wider of the bottom border of the cell above and the top border of the cell below, and it is split half-and-half between the two rows. Before, each cell counted only half of its own border, so rows with only a `border-bottom` were shorter than in Chromium. In one scorecard run, report visual F1 rose from 0.965 to 0.98 and mean fidelity from 0.985 to 0.988.

### Tests
- A test for the shared border split. The suite is now 66 tests and 1206 assertions.

## 2.6.0 — 2026-11-05

### Fixes
- **`line-height: normal`** now rounds ascent, descent and lineGap to whole CSS pixels (0.75pt) separately, as Chromium does. For example, PT Sans at 10pt now gets 13.5pt instead of 12.94pt. In one scorecard run, invoice visual F1 rose from 0.841 to 0.997 and grid from 0.952 to 1.0. Mean fidelity across the six reference documents rose from 0.951 to 0.985. CSS coverage stays at 50/50.
- New `TrueTypeFont::normalLineHeightAt(float $size)`.

## 2.5.0 — 2026-11-05

### Fixes
- **`border-collapse: collapse` column widths:** a cell's min and max content width now counts half of its left and right borders, not the full borders. The long-table reference document now has 5 pages, the same as Chromium; before this fix it had 7. Its visual F1 is now 1.0, up from 0.867. Mean fidelity across the six reference documents rose from 0.928 to 0.951, from a single scorecard run. CSS coverage stays at 50/50.

### Performance
- Hyphenation pieces are now cached per word, font, size, language, limits and line width, so repeated words in long tables are split only once. The output is byte-identical (all example md5 sums match). In a local benchmark, wall-time changes were within run-to-run noise.

### Tests
- A test checks that a collapsed border counts as half its width in column widths. The suite is now 65 tests and 1200 assertions.

## 2.4.0 — 2026-11-05

Chromium-parity fixes for line metrics and the box model. Mean visual fidelity against Chromium went from 0.838 to **0.928** (median of 3 runs). CSS coverage stays at 50/50.

### Fixes
- **`line-height: normal`** is now computed from the font's hhea ascent, descent and lineGap, as Chromium does. OS/2 typo metrics are used only when `USE_TYPO_METRICS` is set or hhea is missing. Before, a fixed 1.2 was used. The built-in UA stylesheet no longer forces `line-height: 1.25` on `body`. The optional `baurpdf.css` theme still sets 1.45.
- **Margin collapsing:** a first child's top margin now collapses with its parent's top margin when the parent has no top padding or border.
- **`border-collapse: collapse`:** each cell now owns half of a shared border, both for its content offset and its height. Before, it owned the full border on every side, which added about one border width to every row and narrowed every column.
- **max-content width** no longer counts leading or trailing spaces.

### Tests
- `tests/V24FeaturesTest.php` (3 tests). The suite is now 64 tests and 1198 assertions.

## 2.3.0 — 2026-11-05

Every fix below was found by the new Chromium side-by-side scorecard (`tools/compare/scorecard.py`). Measured in standards mode, CSS coverage against Chromium went from 43/50 to **50/50** features. Mean visual fidelity across the six reference documents is 0.838; it was 0.807 before these fixes.

### Fixes
- **CSS parser:** a declaration with nested parentheses, such as `width: calc(var(--w) * 2)`, no longer swallows the next declaration. The value splitter now respects nesting and quotes, and has a fast path for plain declarations.
- **`position: relative`** with `top`/`left`/`right`/`bottom` now offsets the box without affecting the flow. It is combined with `transform` when both are set. A box taller than about 90% of a page keeps its normal position, so no content is lost.
- **`overflow: hidden|clip|auto|scroll` with a definite `height`:** the box keeps its height and the content is clipped to it. Before, the box grew to fit its content.
- **Flexbox:** a single-line flex container with a definite `height` or `min-height` now uses that height as the line's cross size, so `align-items: center|flex-end` aligns to the container.
- **`white-space: pre`:** tabs expand to tab stops (`tab-size`, default 8) instead of 4 spaces.
- **Checkbox and radio:** when given a `width` different from their height, they are drawn as a centred square, as in browsers.
- **Multi-column:** the first block in the first column keeps its top margin. After a column break, the margin is truncated, as in Chromium.

### Tooling (dev only)
- `tools/compare/scorecard.py` reports, per axis, cold-process wall time and in-process time (speed), peak RSS and PSS of the process tree (memory), file size, page count, `qpdf --check`, text Jaccard, visual ink-F1, and 50 per-feature CSS checks. `--feat REGEX` runs a quick subset. The reference documents now use `<!DOCTYPE html>`, so Chromium renders them in standards mode (in quirks mode, tables do not inherit `font-size`).

### Tests
- `tests/V23FeaturesTest.php` (6 tests). The suite is now 61 tests and 1182 assertions.

## 2.2.0 — 2026-11-04

### CSS
- **2D `transform`** on HTML blocks: `translate[X|Y]` (with %), `scale[X|Y]`, `rotate`, `skew[X|Y]`, `matrix`, and `transform-origin`. Drawn as one `cm` matrix; the block stays unbreakable. Garbage or non-finite values are ignored.
- **`::before` / `::after`** with `content`: strings, `attr()`, `counter()`/`counters()` (decimal, decimal-leading-zero, lower/upper-alpha/latin/roman, lower-greek, cyrillic), `open-quote`/`close-quote` («» then „“), `url()`.
- **CSS counters**: `counter-reset`/`counter-increment`/`counter-set`, scoped per the spec (siblings reuse a counter, nested elements create a new one), plus the implicit `list-item` counter. Rules containing only `content: none` cost nothing.
- **`column-span: all`**: splits the column set into balanced columns before the spanner, the full-width element, and columns after it.
- **CSS Grid completeness** (new `GridLayout`):
  - `grid-template-areas` + `grid-area`; line numbers, negative lines and `span` on both axes (`grid-row`, `grid-column`, `-start`/`-end`);
  - row-spanning items;
  - `grid-template-rows`/`grid-auto-rows`/`grid-auto-columns`;
  - `auto`, `min-content`, `max-content`, `minmax()` and `fit-content()` tracks sized from content;
  - `auto-fill`/`auto-fit` with `minmax()`;
  - `grid-auto-flow: row|column [dense]`;
  - `justify-items`/`justify-self`, `align-*`, `justify-content`, `place-items`/`place-self`.
  - Rows linked by a row span paginate as one band.

### Fixes
- Form fields (`input`, `select`) now sit on the text baseline of the line instead of below it.
- The dashboard template fits on one landscape A4 page: chart margins inside cards and table cell padding were tightened.

### Performance
- Selector matching is about 3× faster (52 → 16 ms for the 1000-row table): ancestor prefilter (required ancestor tags, classes and ids are precomputed per selector and checked against a per-node ancestor set), cached class sets, and integer sort keys instead of array comparison.

### Tests
- `tests/V22FeaturesTest.php` (8 tests): transform matrix, counters and nested `counters()`, attr and quotes, `column-span` geometry, grid areas/row-span/dense geometry, form baseline, selector prefilter, dashboard page count. Malformed grid and transform input never crashes.

## 2.1.0 — 2026-11-04

### Performance
- Table of 1000 rows: 700 ms → **473 ms** (1.0: 571 ms), memory unchanged at 32 MB. Text (~40 pages): 305 → 207 ms. 8000-row scale test: 2071 → 1405 ms.
- Memoized style cascade (keyed by parent style + matched rules + attributes), word widths, font segments, `boxMetrics`, length parsing and text encoding.

### Layout
- **CSS columns break across pages**; the last segment is balanced, and the rule lines are repeated on every page.
- `hyphenate-limit-chars`; stricter defaults in narrow lines and table cells (min. 8 chars, 3+3 around the break).
- `box-shadow` blur is now a Gaussian approximation through a soft mask (SMask, 3-pass box blur), not stacked layers.
- `radial-gradient()` (circle/ellipse, size keywords, `at` position).
- An absolutely positioned child of `body` resolves against the page; `top` + `bottom` without `height` stretches.

### Accessibility
- **Tagged PDF** (`->tagged()`) and **PDF/UA-1** (`->pdfua()`):
  - a structure tree with Document, H1–H6, P, L/LI/LBody, Table/THead/TBody/TFoot/TR/TH(Scope)/TD (ColSpan/RowSpan), Figure with Alt, Link + OBJR and Form;
  - marked content (MCID), with headers, footers and decoration as Artifacts;
  - ParentTree, `/Tabs /S`, and the XMP `pdfuaid` field.
  - With `->pdfa()` the output is PDF/A-2a. veraPDF reports PASS for ua1, 2a and 2b.

### Forms
- **AcroForm** fields from text/password/checkbox/radio/select/textarea:
  - appearance streams;
  - Cyrillic glyphs embedded for editing;
  - readonly/required/maxlength; radio groups; unique names.
- Buttons are drawn statically.

### SVG
- `<use>` with local references only (external hrefs are ignored), `<symbol>` + viewBox, nested `<svg>` with x/y/viewBox, `<style>` rules (tag, .class, #id), `<switch>`.

### Templates
- New built-in templates: `contract`, `certificate` (A4 landscape), `dashboard` (A4 landscape, KPI grid + charts). New examples: `contract.pdf`, `certificate.pdf`, `dashboard.pdf`, `form.pdf`.

### Quality
- 47 tests / 1101 assertions, PHPStan level max clean; an optional veraPDF check via `VERAPDF=…`.

## 2.0.0 — 2026-11-04

### Typography
- Justified text uses **Knuth-Plass** line breaking, with a greedy fallback for ragged text and very long paragraphs. This removes the large gaps between words in narrow justified columns.
- **Dictionary hyphenation** (Liang patterns) for Russian, Kazakh and English. The language comes from `lang` or from the script. `hyphens: none | manual | auto` is supported, and `auto` is the default for justified text.
- `&nbsp;` really is non-breaking now: `1&nbsp;000&nbsp;000` is never split, and min-content widths account for it.

### Layout
- `float: left/right` with text wrap; `clear`; floats stack side by side; the parent block grows to contain its floats.
- CSS columns: `columns`, `column-count`, `column-width`, `column-gap`, `column-rule`, with height balancing.
- `box-shadow`: several comma-separated shadows, offset, blur (stacked translucent layers), spread, and border-radius. `inset` is ignored.

### Tables
- `<tfoot>` repeats at the bottom of every page the table spans. Turn it off with `data-repeat-footer="false"`.
- **Auto-landscape**: a table that would need its font shrunk below 65% goes onto its own landscape page(s). The rest of the document stays portrait. Force it with `data-overflow="landscape"`; disable it with `->autoLandscape(false)`.

### PDF
- **AES-256 encryption** (V5/R6, AESV3) is the default for `->password()`. Pure-PHP AES (checked against FIPS-197) is used, and OpenSSL when available. RC4-128 is still available via `->encryption('rc4')`.
- **PDF/A-2b** via `->pdfa()`: XMP metadata, an embedded sRGB ICC OutputIntent (generated in code), and printable annotations. All examples pass veraPDF 1.30 (PDF/A-2B).
- Smaller output: one text operator per line, syllables merged back into words, group ops shared between pages.

### Performance and memory
- Text document (~40 pages): 50 MB → 18 MB peak and 224 KB → 103 KB. That is now below mPDF (22 MB / 132 KB).
- Table, 1000 rows: 66 MB → 32 MB; 8000 rows: 86 MB.
- Identical computed styles are interned; each page is freed right after it is written.
- `BAURPDF_PROFILE=1` also reports memory per phase.

### Quality
- PHPStan **level max: 0 errors**. One rule, `missingType.iterableValue`, is disabled and documented in `phpstan.neon`. Yii2 is stubbed so the analysis needs no framework.
- 11 new tests: 37 tests and 1001 assertions in total.
- New example `examples/output/v2-features.pdf` (PDF/A-2b) and `tools/bench/`.

### Notes on compatibility
- `->password()` now produces AES-256 instead of RC4. Very old viewers (before Acrobat X) need `->encryption('rc4')`.
- `->pdfa()` together with `->password()` ignores the password and adds a warning, because PDF/A forbids encryption.

## 1.0.0
- First release: HTML/CSS (flex, grid, variables, calc), tables, charts, QR/Code128, TOC/outline, fonts, RC4 encryption, Yii2, CLI.
