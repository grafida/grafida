# Requirements

- A Joomla **5.4 or 6.x** site with the Web Services API enabled, and an API token for an account
  authorised for API access. Super Users work by default. Authors and other non-Super-User accounts need
  some set-up on the site; on Joomla 5.4.8, 6.1.3 and later that includes "Access Administration
  Interface" on Articles, Media and Tags, which does not give them back-end access. See
  [Custom API access](https://grafida.app/docs/desktop/Custom-API-Access.html).
- To run a pre-built release:
  - **macOS 12 Monterey or later**, on Apple Silicon or on an Intel Mac. macOS 27 is the last macOS
    for Intel Macs: when macOS 28 drops Intel and Rosetta 2, Grafida for Intel Macs becomes
    deprecated, and when macOS 29 lands it is discontinued (no more Intel builds, and the update
    check stops offering new releases to Intel Macs).
  - **Windows 10 22H2 or later** (x64), with the Microsoft Edge WebView2 Runtime. It ships with
    Windows 11 and arrives through Windows Update on Windows 10; without it, the installer warns and
    offers Microsoft's download page, and the app shows an alert.
  - **Linux** (x86_64 or arm64) with WebKitGTK 4.1, version 2.40 or later, GTK 3 and glibc 2.35 or
    later: Ubuntu 22.04, Debian 12, Fedora and Arch are new enough. The packages are
    `libwebkit2gtk-4.1-0` and `libgtk-3-0` on Debian and Ubuntu, `webkit2gtk4.1` on Fedora and
    `webkit2gtk-4.1` on Arch. Spell checking also needs a Hunspell dictionary for each language you
    write in; see [Editing Articles](https://grafida.app/docs/desktop/Editing-Articles.html#spell-checking).
