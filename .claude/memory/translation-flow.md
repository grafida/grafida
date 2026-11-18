# Translation flow (must be followed every time)

Read before adding or changing any UI string or language file.

The canonical source is **en-GB**. Translations use the **Joomla INI** format. Before each
translation run, consult the per-language glossary in `language/glossaries/<tag>.md` (create it
if missing) and update it with any new terms — glossaries keep terminology consistent. A new
language needs only its `<tag>/<tag>.ini` file (e.g. `language/de-DE/de-DE.ini`) — there is no
`.sys.ini` and no manifest to register it in (`LanguageService` discovers languages by scanning
the directory at runtime). Each `<tag>.ini` MUST include a `GRAFIDA_LANGUAGE_ENDONYM`
key holding the language's name in its own tongue (e.g. `"Français (France)"`) — `LanguageService`
reads it to build the runtime language list, so the new language appears in the UI automatically.
When a generated file is large, write it in ~10–12 KiB chunks, each
ending on a whole line. The shipped languages are: en-GB (source), el-GR, fr-FR, de-DE,
es-ES, it-IT, pt-PT.

## Layout myths to avoid

Grafida's translations are not a Joomla component's. Language files are `language/<tag>/<tag>.ini`, **not**
`<tag>.com_grafida.ini`; there is **no `.sys.ini`** and **no `language/grafida.xml` manifest** to register a
language in (`LanguageService::available()` discovers languages at runtime by scanning the directory and reading
each file's required `GRAFIDA_LANGUAGE_ENDONYM` key, so adding a language needs no code change). The shipped-UI key
list is **`I18n\UiStrings::KEYS`**, not `UI_KEYS` in `ApiController` (which is only a dispatcher).

**Why:** translations must stay consistent in terminology and actually be loadable by the app; large language
files otherwise blow the response token limit, and inconsistent terms accumulate across seven languages. Following
a Joomla-component layout produces files the app never loads.

**How to apply:** follow the flow above on every translation run; new translatable button labels follow it too
(en-GB canonical, the `I18n\UiStrings::KEYS` list, all shipped languages, glossaries).
