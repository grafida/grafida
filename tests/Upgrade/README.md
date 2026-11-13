# Upgrade test: 0.4 over Boson's 0.3

The tooling behind #92's "Upgrade over the shipped Boson 0.3" item, on clean test guests (grafida/shell
`docs/dev/macos-test-vms.md`, `windows-test-vm.md`, `linux-test-vms.md`). It is not part of `composer
test` and nothing here ships.

| File | What it does | Runs on |
|---|---|---|
| `build-seed.php` | packs `seed-0.3.php` and a Grafida **0.3** tree into `seed.phar` | the host |
| `seed-0.3.php` | writes the "before" data through 0.3's own code | the guest, on 0.3's own PHP |
| `after-check.py` | compares what 0.4 sees with the seed's manifest | the guest, through the debug shell's automation channel |
| `linux-seed.py` | builds the Linux seed binary out of 0.3's combined runtime | the host or the guest |
| `windows-token.ps1` | prints a secret from 0.3's DPAPI store, for `--token-from-store` | the guest's desktop session (`winvm.sh desktop`) |

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
- **Windows:** 0.3's installed `grafida.exe` (a sibling-phar stub) copied as `seed.exe` beside
  `seed.phar`, then `seed.exe --ai-endpoint=http://10.0.2.2:8766/v1 --export-dir=%USERPROFILE%\Documents
  --manifest=%USERPROFILE%\seed-manifest.json` **in the desktop session** with the real `%APPDATA%`
  (`winvm.sh desktop`, not `winvm.sh run`, which points `APPDATA` at `C:\winvm\run`): DPAPI is keyed to
  the logon, and an SSH session's secrets would not be the user's.
- **Linux:** Boson's 0.3 Linux binary is **not** a sibling-phar stub but phpmicro's stock combined
  runtime (ELF, INI block, 0.3's PHAR in one file), so a renamed copy would run 0.3 itself.
  `python3 -I linux-seed.py grafida/grafida seed.phar seed` writes the same ELF and INI block followed by
  `seed.phar`; run `./seed …` through `linuxvm.sh session`. Two traps on a clean guest:
  - **Ubuntu 22.04 has no `secret-tool`** (`libsecret-tools`), which Grafida's Secret Service store
    runs, 0.3's and 0.4's alike: without it both fall back to asking for plain text. Install it first.
  - **An autologin session has no unlocked keyring**: the first secret makes GNOME ask for a keyring
    password, and `secret-tool` waits. Do what PAM does at a password login, in the session:
    `pkill -x gnome-keyring-d; printf PASSWORD | gnome-keyring-daemon --daemonize --login; eval "$(gnome-keyring-daemon --start --components=secrets)"`.
  - **0.3 cannot start on Ubuntu 22.04**: its libboson needs GTK ≥ 4.10 (`gtk_file_dialog_save`) and
    22.04 has 4.6. The seed still runs (it needs no webview), but nobody has 0.3 data there.

Then install 0.4 as a user would, check what a user sees with the release app (the first launch, the
Dock or Start menu, the update check), and only then, with a debug shell swapped into a **copy** of the
installed app and started with `--automation` on the same user's data:

```sh
python3 tests/Upgrade/after-check.py seed-manifest.json shell/scripts/dev/automation.py \
    --token-from-store 'security find-generic-password -s Grafida -w -a'     # macOS
python3 -I tests/Upgrade/after-check.py seed-manifest.json ../shell/scripts/dev/automation.py \
    --token-from-store '../shell/scripts/dev/winvm.sh desktop windows-11 tests/Upgrade/windows-token.ps1'   # Windows, from the host
python3 -I after-check.py seed-manifest.json automation.py \
    --token-from-store 'secret-tool lookup application Grafida account'      # Linux, in the guest's session
```

On Windows the debug copy is started with `WINVM_ENV='APPDATA=C:\Users\Quickemu\AppData\Roaming
LOCALAPPDATA=C:\Users\Quickemu\AppData\Local' winvm.sh run VM <copy>\Grafida.exe --automation`, so it
runs on the real data while its automation file stays where `winvm.sh tunnel` looks for it.
`windows-token.ps1` unprotects `%APPDATA%\Grafida\secrets\<sha1(reference)>.bin` in the desktop session.

It checks that the 0.4 worker answers; that the site, the preferences, the AI service and its key,
the site token in the OS store, and both drafts survive byte for byte (but for the `boson://` → `shell://`
rewrite of their picture URLs); that the pictures are served unchanged; and that the editor renders them.
