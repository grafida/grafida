# Upgrade test: 0.4 over Boson's 0.3

The tooling behind #92's "Upgrade over the shipped Boson 0.3" item, on clean test guests (grafida/shell
`docs/dev/macos-test-vms.md`, `windows-test-vm.md`, `linux-test-vms.md`). It is not part of `composer
test` and nothing here ships.

| File | What it does | Runs on |
|---|---|---|
| `build-seed.php` | packs `seed-0.3.php` and a Grafida **0.3** tree into `seed.phar` | the host |
| `seed-0.3.php` | writes the "before" data through 0.3's own code | the guest, on 0.3's own PHP |
| `after-check.py` | compares what 0.4 sees with the seed's manifest | the guest, through the debug shell's automation channel |

## Why a seed, and why 0.3's code

Boson's 0.3 has no automation channel, so its window cannot be driven. The seed is the next best
thing to typing it in: it is **0.3's own code** (its kernel, its migrations, its secret store, its
`InlineImageExtractor`), running on **0.3's own PHP** (the sibling-phar SFX stub out of the 0.3 package,
copied and renamed), in the desktop session of a guest where 0.3 was installed and launched once.
Two things go round the kernel, because they need a network or Boson's dialog: the site (its creation
tests the connection) is written by 0.3's repository and 0.3's own `persistToken()`, and the `.grafida`
export is asked for with a plain directory, which 0.3's route accepts. The macOS secrets go through
`/usr/bin/security` in 0.3 as in 0.4, so an item the seed writes has exactly the access list a real 0.3
item has.

## Steps

```sh
# Host: a 0.3 tree and the PHAR (PHP 8.4+ with phar.readonly off).
git worktree add --detach /tmp/g03 0.3 && (cd /tmp/g03 && composer install --no-dev --no-scripts)
php -d phar.readonly=0 tests/Upgrade/build-seed.php /tmp/g03 seed.phar
```

In the guest, after 0.3 is installed and has been launched and quit once:

- **macOS:** copy the bundle's `Contents` into a folder **without** the `.app` extension and put
  `seed.phar` where `grafida.phar` was:
  `mkdir -p seedkit/Seed && ditto /Applications/Grafida.app/Contents seedkit/Seed/Contents && cp seed.phar seedkit/Seed/Contents/Resources/grafida.phar`.
  Then run `seedkit/Seed/Contents/MacOS/grafida --ai-endpoint=http://192.168.64.1:8766/v1 --export-dir="$HOME/Documents" --manifest="$HOME/seed-manifest.json"`
  **in the desktop session** (`sudo launchctl asuser $(id -u) sudo -u $USER …`).
  The layout is load-bearing. A stub renamed on its own is killed at exec ("invalid Info.plist":
  its signature is bound to the bundle's `Info.plist`). A modified `.app` copy is "damaged and can't
  be opened" on macOS 27, even re-signed ad hoc. The `Contents/` folder keeps the Developer ID
  signature valid, and nothing assesses it as an app.
- **No Python in a clean guest:** `python3` there is a stub that raises the Command Line Tools
  dialog. Install the tools only after the release app's first launch has been checked.
- **Linux / Windows:** the 0.3 package's SFX stub copied as `seed` (`seed.exe`) beside `seed.phar`.

Then install 0.4 as a user would, check what a user sees with the release app (the first launch, the
Dock or Start menu, the update check), and only then, with a debug shell swapped into a **copy** of the
installed app and started with `--automation` on the same user's data:

```sh
python3 tests/Upgrade/after-check.py seed-manifest.json shell/scripts/dev/automation.py \
    --token-from-store 'security find-generic-password -s Grafida -w -a'     # macOS
```

It checks that the 0.4 worker answers; that the site, the preferences, the AI service and its key,
the site token in the OS store, and both drafts survive byte for byte; that the `boson://` pictures
are served unchanged; and that the editor renders them.
