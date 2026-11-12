# Requirements

## Desktop

Grafida runs on macOS 12 Monterey or later (Apple Silicon or Intel), Windows 10 22H2 or later (x64), and Linux (x86_64 or arm64) with WebKitGTK 4.1. It has minimal memory and CPU requirements; if you can run a lightweight browser, you can run Grafida.

### macOS

You need macOS 12 Monterey or later. There are two downloads: one for Macs with Apple Silicon (M1 and later) and one for Intel Macs. Pick the one that matches your Mac. On a Mac with an older version of macOS, the system refuses to open Grafida; macOS has to be updated.

> [!NOTE]
> **Grafida for Intel Macs has a limited lifetime.** macOS 27 is the last version of macOS for Intel Macs. When macOS 28 drops Intel Macs and Rosetta 2, Grafida for Intel Macs becomes deprecated. When macOS 29 is released, it is discontinued: there are no more Intel builds, and the update check stops offering new releases to Intel Macs.

### Windows

You need Windows 10 22H2 or later, or Windows 11, on an x64 processor.

You also need the Microsoft Edge WebView2 Runtime. It ships with Windows 11 and arrives through Windows Update on Windows 10, so you almost certainly have it already. If it is missing, the installer warns you and offers to open Microsoft's download page, and Grafida shows an alert when you start it. You can also [install it from Microsoft](https://developer.microsoft.com/microsoft-edge/webview2/).

> [!IMPORTANT]
> This application may not work on Windows 11 Home with Smart App Control (SAC) enabled due to a limitation on Microsoft's side. You may try [disabling SAC](https://www.elevenforum.com/t/turn-on-or-off-smart-app-control-in-windows-11.4996/). For more information, please read the project's [README](https://github.com/grafida/grafida/#readme).

### Linux

You need an x86_64 or arm64 computer with WebKitGTK 4.1, version 2.40 or later, GTK 3 and glibc 2.35 or later. Ubuntu 22.04, Debian 12, and current releases of Fedora and Arch Linux are new enough; Grafida is tested on Ubuntu 22.04, Fedora and Arch Linux.

Install these packages before you start Grafida for the first time:

| Distribution | Command |
|---|---|
| Debian, Ubuntu and their derivatives | `sudo apt install libwebkit2gtk-4.1-0 libgtk-3-0 libsecret-tools` |
| Fedora | `sudo dnf install webkit2gtk4.1 gtk3 libsecret` |
| Arch Linux | `sudo pacman -S webkit2gtk-4.1 gtk3 libsecret` |

**WebKitGTK 4.1 and GTK 3** draw Grafida's window and everything in it. Without them Grafida cannot start: it shows a message listing the libraries it could not find.

**`secret-tool`** is how Grafida reaches your desktop's secret store, where it keeps your sites' API tokens and your AI services' API keys: GNOME Keyring on GNOME, KWallet on KDE Plasma. It is in the `libsecret-tools` package on Debian and Ubuntu, and in `libsecret` on Fedora and Arch Linux. A new Ubuntu or Debian installation does not include it, even though GNOME Keyring is installed and running. Without `secret-tool`, Grafida cannot find a secret store, so it asks whether to save each token as plain text in its database instead; see [Secrets & Security](Secrets-Security#when-no-secret-store-is-available). Fedora and Arch Linux desktops usually have it already.

The first time Grafida saves a token, your desktop may ask you to set up its secret store. On GNOME, if you log in automatically, it asks you to choose a password for a new keyring. On KDE Plasma, it asks you to create a wallet: choose **Classic, blowfish encrypted file**, because the GPG option only works if you already have a GPG key.

For spell checking you also need a Hunspell dictionary for each language you write in; see [Editing Articles](Editing-Articles#spell-checking).

## Server

On the server side, you need a Joomla **5.4 or 6.x** site, and a Joomla API token for a user account that is allowed to use these features. Super User accounts can do this out of the box. Other accounts, such as Authors, need a little set-up on the site first; on Joomla 5.4.8, 6.1.3 and later that includes one extra permission, which does not give them back-end access. Please read the [Custom API access](Custom-API-Access) page.
