# Requirements

- A Joomla **4.0 or later** site with the Web Services API enabled, and an API token for an account
  authorised for API access. Super Users work by default. Authors and other non-Super-User accounts need
  some set-up on the site; on Joomla 5.4.8, 6.1.3 and later that includes "Access Administration
  Interface" on Articles, Media and Tags, which does not give them back-end access. See
  [Custom API access](https://grafida.app/docs/desktop/Custom-API-Access.html).
- To run a pre-built release: **macOS 15 Sequoia+**, **Windows 10+** (with the Microsoft Edge
  WebView2 Runtime, which ships with Windows 11 and current Windows 10), or **Linux** with GTK4
  and WebKitGTK 6.0 (`libgtk-4-1`, `libwebkitgtk-6.0-4`). The macOS floor is set by the prebuilt
  Boson webview library, which is linked against a macOS 15 SDK and cannot be loaded by macOS 14
  Sonoma or earlier ([gh-58](https://github.com/grafida/grafida/issues/58)).
