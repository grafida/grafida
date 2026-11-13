# Windows Code Signing with Azure Artifact Signing on macOS

## Overview

Azure Artifact Signing (formerly "Azure Trusted Signing") is Microsoft's cloud-based code signing service. Private keys are stored in Azure's cloud HSM — no physical USB token required. Signing is done via [Jsign](https://ebourg.github.io/jsign/), a cross-platform Java tool that handles Windows Authenticode signing on macOS and Linux.

**Cost**: Basic tier is ~€9.99/month, covering 5,000 signatures — more than enough for small-volume releases.

**Important**: Azure Artifact Signing issues certificates with a 3-day validity window. This is by design. As long as you always include a timestamp when signing (covered below), the signature on the file remains valid indefinitely. Never skip the timestamp.

**SmartScreen caveat**: Signing does not eliminate SmartScreen warnings for new files. SmartScreen reputation accumulates based on download volume over time. For tools with a small install base, users will still need to click "More info → Run anyway". What signing does provide is that the warning shows your publisher name instead of "Unknown Publisher", and it improves behaviour with enterprise AV/EDR tools and Windows AppLocker policies.

> [!IMPORTANT]
> **Signing fails with `HTTP Error 403 - Forbidden`? Check these two things first, in this order.**
>
> 1. **The names in `build/build.properties`.** The signing account is **`akeeba-signing`** and the
>    certificate profile **`AkeebaPublic`**, so `windows.sign.alias=akeeba-signing/AkeebaPublic`, and
>    `windows.sign.keystore` must be the Account URI the portal shows for that account
>    (`weu.codesigning.azure.net` for West Europe). A machine set up from an older copy of these notes
>    can carry other names and fail with nothing else wrong. The build prints the profile it uses on
>    its first line (`==> Grafida <v>, Windows x64, signed: Trusted Signing (<account>/<profile>)`):
>    compare it with the portal before anything else.
> 2. **New terms of service.** Microsoft changes the Artifact Signing terms from time to time, and
>    signing is refused until they are accepted again. Portal → **Artifact Signing Accounts** →
>    **akeeba-signing** → **Objects** → **Identity validations**: if it asks you to accept updated
>    terms, accept them. Nothing else tells you; Jsign only reports the 403.
>
> Jsign hides Azure's reason. To read it, right after a failed build (the Azure CLI is still logged in
> as the service principal):
>
> ```sh
> TOKEN=$(az account get-access-token --resource https://codesigning.azure.net --query accessToken -o tsv)
> curl -sS -i -X POST \
>   "https://weu.codesigning.azure.net/codesigningaccounts/akeeba-signing/certificateprofiles/AkeebaPublic/sign?api-version=2022-06-15-preview" \
>   -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
>   -d "{\"signatureAlgorithm\":\"RS256\",\"digest\":\"$(head -c 32 /dev/zero | base64)\"}"
> ```
>
> Then, if it is neither of the above: the subscription is still **Active** (Subscriptions → Overview;
> an expired card disables it, and its resources answer 403), the certificate profile is **Active**,
> and the service principal still holds **Artifact Signing Certificate Profile Signer** on the account
> (Access control (IAM) → Role assignments). A 401 rather than a 403 is the token itself, not any of
> these.

---

## Local Prerequisites

Install via Homebrew:

```sh
brew install azure-cli jsign osslsigncode makensis 1password-cli
```

Verify:

```sh
az --version
jsign --version
op --version
makensis -VERSION     # 3.08 or later
```

The 1Password CLI (`op`) must be signed in (`op signin`) and the service-principal secret from
Step 8 below must be saved as a 1Password item — the build reads `appId`/`password`/`tenant` from
it via `op item get`, rather than keeping them in plaintext in `build/build.properties`.

That's all you need locally.

---

## Azure Setup

### Step 1: Paid Azure Subscription

Azure Artifact Signing does not work on free, trial, or sponsored subscriptions. You need a pay-as-you-go or Enterprise Agreement subscription. Set one up at [portal.azure.com](https://portal.azure.com) if you don't already have one.

### Step 2: Register the CodeSigning Resource Provider

```sh
az login
az provider register --namespace Microsoft.CodeSigning

# Poll until it says "Registered" (takes 2–3 minutes)
az provider show --namespace Microsoft.CodeSigning --query registrationState -o tsv
```

### Step 3: Create a Resource Group

```sh
az group create --name GrafidaCodeSigning --location westeurope
```

Western Europe is the appropriate region for Cyprus/EU.

### Step 4: Create an Artifact Signing Account

```sh
az trustedsigning create \
  --name akeeba-signing \
  --resource-group GrafidaCodeSigning \
  --location westeurope \
  --sku Basic
```

### Step 5: Assign the Identity Verifier Role to Your Own Account

Before you can submit an identity validation request in the portal, your Azure user account needs the **Artifact Signing Identity Verifier** role. Run:

```sh
RESOURCE_ID=$(az trustedsigning show \
  --name akeeba-signing \
  --resource-group GrafidaCodeSigning \
  --query id -o tsv)

MY_OBJECT_ID=$(az ad signed-in-user show --query id -o tsv)

az role assignment create \
  --assignee $MY_OBJECT_ID \
  --role "Artifact Signing Identity Verifier" \
  --scope $RESOURCE_ID
```

Wait a minute or two for the role assignment to propagate before proceeding.

### Step 6: Submit Identity Validation

This is the only manual step and the slowest part. Microsoft needs to verify the publisher's identity before issuing publicly trusted certificates. Expect a few days to two weeks.

In the Azure Portal ([portal.azure.com](https://portal.azure.com)):

1. Use the search bar at the top and search for **"Trusted Signing"**.
2. In the results, click **Artifact Signing Accounts** (under Services). Note: the portal uses the old name "Trusted Signing" in search but lists the resource as "Artifact Signing Accounts".
3. Click on **akeeba-signing**.
4. In the left menu, find **Identity validation** and click **New identity validation**.
5. Select **Individual**.
6. Fill in Nicholas K. Dionysopoulos' identity details as requested by the portal.
7. Submit and wait for the approval email.

Nothing in the steps below is possible until identity validation is approved.

### Step 7: Create a Certificate Profile

Once identity validation is approved, return to the **akeeba-signing** account in the portal:

1. In the left menu, click **Certificate profiles** → **New**.
2. Set the profile type to **Public Trust**. This is mandatory — "Private Trust" profiles are for internal enterprise apps only and will not produce publicly trusted signatures.
3. Name it `AkeebaPublic` (or similar — note the exact name, and put it in `windows.sign.alias`).

### Step 8: Create a Service Principal for Non-Interactive Signing

```sh
az ad sp create-for-rbac --name "grafida-codesigning" --output json
```

The output looks like:

```json
{
  "appId": "<client-id>",
  "password": "<client-secret>",
  "tenant": "<tenant-id>"
}
```

**Save all three values to 1Password immediately.** The `password` (client secret) is only shown once and cannot be retrieved again.

### Step 9: Assign Signing Role to the Service Principal

```sh
RESOURCE_ID=$(az trustedsigning show \
  --name akeeba-signing \
  --resource-group GrafidaCodeSigning \
  --query id -o tsv)

SP_OBJECT_ID=$(az ad sp show --id <appId> --query id -o tsv)

az role assignment create \
  --assignee $SP_OBJECT_ID \
  --role "Artifact Signing Certificate Profile Signer" \
  --scope $RESOURCE_ID
```

---

## Signing a Binary

By hand, one file at a time. The build does all of this itself (see "Wiring into the build");
this recipe is for checking the Azure side, or signing something outside the build.

### Authenticate

```sh
az login --service-principal \
  -u <appid> \
  -p <password> \
  --tenant <tenant-id>
```

Or, using 1Password, something like this:

```sh
az login --service-principal \
  -u "$(op item get 'Code signing secrets' --fields label=appId)" \
  -p "$(op item get 'Code signing secrets' --fields label=password --reveal)" \
  --tenant "$(op item get 'Code signing secrets' --fields label=tenant)"
```

### Sign

```sh
jsign \
  --storetype TRUSTEDSIGNING \
  --keystore weu.codesigning.azure.net \
  --storepass "$(az account get-access-token \
    --resource https://codesigning.azure.net \
    --query accessToken -o tsv)" \
  --alias akeeba-signing/AkeebaPublic \
  --tsaurl http://timestamp.acs.microsoft.com \
  --tsmode RFC3161 \
  grafida.exe
```

The `--tsaurl` argument is not optional. Without it, the signature expires when the 3-day certificate expires, making the signed file untrusted after that window.

### Verify the Signature

To confirm the signature was applied correctly:

```sh
osslsigncode verify grafida.exe
```

**This will report the signature as untrusted even when it is correct**, because the Microsoft
root certificate isn't in this machine's Keychain. Read the rest of `osslsigncode`'s output (the
signer, timestamp, and certificate chain details) to confirm the signature is actually there and
correct — don't trust the exit status. Because of this, **`osslsigncode verify`'s exit status must
never gate the build**. shell-packager's own check compares the signed digest with the file and
checks the chain only when `WINDOWS_SIGN_CAFILE` names the roots (see "Wiring into the build").

---

## Wiring into the build

From 0.4 (grafida/grafida#92) the Windows release build is the native shell's x64 installer,
built **on the Mac** (Linux works too) by grafida/shell-packager's `windows-installer` from
`shell-package.json` (its `runtimes.windows` and `windows` sections) and Grafida's own NSIS
script, `build/shell-windows-installer.nsi`. Entry points: `phing package-windows-x86` (part of
`phing package`), `composer build` (`scripts/build-all.sh`), or `scripts/make-windows-installer.sh`
(`composer build:windows`; its arguments go to shell-packager). Output:
`build/dist/Grafida-<version>-windows-amd64-Setup.exe` + `.sha256`.

Install the tools beside `jsign` (makensis 3.08 or later, for `!uninstfinalize`):

```sh
brew install osslsigncode makensis
```

⚠️ makensis 3.13 on macOS aborts with `std::bad_alloc`, even on an empty script, when no locale is
set (an agent's or a CI shell can have none). `make-windows-installer.sh` defaults `LANG` to
`en_US.UTF-8` for that reason.

What it builds, in `build/shell-windows/Grafida`:

```
Grafida.exe            the shell (a GUI program), with Grafida's icon and a version resource
grafida-worker.exe     the phpmicro GUI-subsystem SFX stub ("Grafida worker"), likewise
grafida-worker.phar    the worker payload (extra-INI block + PHAR), a sibling file
shell.json
Grafida.ico
LICENSE.txt
```

* **The runtimes are downloaded, never built here.** The shell (a grafida/shell release) and the
  SFX (the immutable grafida/phpmicro `sfx-20261110.27`) are pinned by SHA-256 in
  `shell-package.json` and cached in `~/.cache/grafida-shell-packager`, never `build/sfx/`. See
  [`03-sfx-maintenance.md`](03-sfx-maintenance.md).
* **No Visual C++ runtime is needed or fetched.** The shell links the CRT statically, and the SFX
  imports system DLLs only (its CI check fails otherwise).
* **Icon and version resources are written in PHP** (no `rcedit`), keeping each executable's
  application manifest. `FileDescription` is what Task Manager names the process by.
* **The installer** is per user, no elevation, in `%LOCALAPPDATA%\Programs\Grafida` under the
  uninstall key `Grafida` — the identity Boson's build had. Before it writes anything it removes a
  Boson install and a gh-91 shell-preview install it finds. The user's data
  (`%APPDATA%\Grafida`) is never touched. No WebView2 bootstrapper: without the runtime the
  installer warns and offers the download page.
* **The worker check** (ini probe + `ready`) runs only on a Windows host, so a Mac build says it
  skipped it; the test guests run it and `tests/Pipe`.

**Signing.** Phing's `windows.sign.op-item` alone decides whether the build is signed. The
properties live in `build/build.properties` (gitignored — copy `build/build.sample.properties`
first):

```properties
windows.sign.op-item=Code signing secrets
windows.sign.op-field-appid=appId
windows.sign.op-field-password=password
windows.sign.op-field-tenant=tenant
```

(`windows.sign.keystore` / `windows.sign.alias` / `windows.sign.tsaurl` also exist with sensible
defaults matching Steps 4/7/the timestamp URL above — override only if you named things
differently.)

* **`windows.sign.op-item` empty** (the default): `package-windows-x86` runs the script with no
  signing settings, and nothing is signed. The script also clears any signing credentials it
  inherits (`WINDOWS_SIGN_ENDPOINT`, `_PROFILE`, `_TOKEN`, `_PKCS12`, `_PKCS12_PASSWORD`), so
  nothing signs by accident. The same "blank disables it" convention as the macOS
  `macos.sign.identity`/`macos.notary.profile` properties (see
  [`01-macos-signing.md`](01-macos-signing.md)).
* **Set**: the target passes it and the other `windows.sign.*` properties to
  `scripts/make-windows-installer.sh`, which reads the service principal from 1Password
  (`op item get`), logs `az` in, fetches an access token for `https://codesigning.azure.net`, and
  hands shell-packager `WINDOWS_SIGN_ENDPOINT` (the keystore), `WINDOWS_SIGN_PROFILE` (the alias),
  `WINDOWS_SIGN_TOKEN` and `WINDOWS_SIGN_TSAURL`. A signed build needs `az`, `jsign`, `op`,
  `osslsigncode` and `makensis` on the PATH; the script stops early, naming the missing one.

shell-packager runs Jsign itself, with the token as an `env:` reference rather than on the command
line, on **four files a build**: the shell, the worker stub, the uninstaller (from inside makensis,
through the `.nsi`'s `!uninstfinalize '${SHELL_PACKAGER_SIGN}' = 0`, since the uninstaller lives
inside the installer and nothing can reach it later) and the installer. It **refuses** to sign
any PE with data past its image (an SFX with its PHAR still appended), except the installer, whose
NSIS archive is an overlay by design. Each signature is checked with `osslsigncode`: the signed
digest must match the file; the certificate chain only when `WINDOWS_SIGN_CAFILE` names its
roots, which is why an untrusted-root report from a plain `osslsigncode verify` on the Mac never
fails the build.

**Critical: mind the quota.** Azure Artifact Signing's Basic tier is capped at **5,000
signatures/month**, and a signed build costs four. Leave `windows.sign.op-item` blank on a dev
machine, and set it only for release builds.

For a one-off `scripts/build-all.sh` or `scripts/make-windows-installer.sh` run outside Phing,
export the same settings instead of using `build.properties`:

```bash
export WINDOWS_SIGN_OP_ITEM="Code signing secrets"
```

### The stub and its PHAR stay two files

A phpmicro self-executable is a PE stub with the application PHAR appended after it. Authenticode
writes its certificate into the PE's Attribute Certificate Table, which the PE spec requires to be
the **last** thing in the file — so Jsign appends it *after* the PHAR. A PHAR stores its own
signature as the last bytes of the file, and PHP verifies it by seeking to EOF; once the
certificate sits past the PHAR, the app **dies at startup with "broken signature"**. (The
Authenticode signature itself is valid — it hashes the overlay too — but that does not help;
nothing appendable can survive after a PHAR.)

So shell-packager never builds a combined binary: `grafida-worker.exe` is the bare patched SFX
(the grafida/phpmicro `sibling-phar` fork), signed, and `grafida-worker.phar` sits beside it, a
plain data file. The patched stub loads `<self-without-.exe>.phar` from its own directory at run
time, and knows to look past its own certificate table (see
[`02-signing-architecture.md`](02-signing-architecture.md)).

---

## AV False Positives

Signing reduces but does not eliminate AV false positives. PHAR-based tooling in particular is routinely flagged by antivirus software regardless of signing status. Document this in your release notes and provide users with instructions for adding an exclusion if needed.
