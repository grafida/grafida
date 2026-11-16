# Build and packaging overview

Read before running `composer build` / Phing targets. The full detail is in `.claude/rules/build-and-packaging.md`.

## Build & packaging (one step)

`composer build` → `scripts/build-all.sh` packages every platform into `build/dist/` (the two macOS
DMGs on a Mac, the two Linux tarballs, the Windows installer, each with a `.sha256`), through the same
`scripts/make-*.sh` helpers the Phing `package-*` targets call; `phing package` (the default target,
and `all`) does the same with signing from `build/build.properties`; `phing release` adds the GitHub
release, `grafida.json` and the CDN upload. Everything is built and signed **on the Mac** by
grafida/shell-packager over `shell-package.json`. The **CHANGELOG is the single source of truth for
the version** — `build/tasks/set-version.php` stamps its topmost heading into `App::VERSION` before
every package. `node`+`npm` are build prerequisites (the front-end libraries are vendored by
`composer run-script vendor:assets`).

⚠️ **The full build, code-signing and release detail lives in `.claude/rules/build-and-packaging.md`**,
which loads automatically when you touch `build/`, `scripts/`, `build.xml`, `shell-package.json`,
`composer.json`, the CHANGELOG or the release notes. The one rule to carry around without it:
signing on **both** macOS and Windows depends on the phpmicro `sibling-phar` SFX stub and its PHAR
being **two files** — signing a *combined* binary appends the certificate past the PHAR, corrupts
its signature, and the app dies at startup. And every fetched binary is checked against a SHA-256 never
typed by hand: the shell's come from GitHub into `shell-package.lock` (`shell-packager update`), the
SFX's are computed from the downloaded bytes and proven with `shell-packager fetch`.
