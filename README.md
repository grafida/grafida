# Grafida

**Joomla content editing, untethered.**

[Download from GitHub](https://github.com/grafida/grafida/releases/latest)

Grafida is also available on [iPad / iPhone](https://github.com/grafida/grafida-ipad). Learn more on [Grafida.app](https://grafida.app).

> [!IMPORTANT]
> Developing and maintaining this software is neither easy, nor free. Please [support me](https://www.dionysopoulos.me/about-me.html#support-me) to keep it maintained and available free of charge.

## What's this?

Grafida is an offline-first article editor for Windows, Linux, and macOS desktops and laptops.
It lets writers connect to  one or more Joomla! 4+ sites, create or edit articles locally, manage
article metadata and media, and publish through Joomla's Web Services API only when they are ready.

Drafts remain in the app's on-device database, while API tokens are protected by your OS. The
editor supports formatted HTML, Markdown import, custom fields, local and remote images, and an
optional AI assistant using a user-selected provider. Local inference is supported through the use
of third-party tools with an OpenAI-compatible API such as LM Studio and Ollama.

Grafida does not host sites, provide a Grafida account, or send data through an intermediary service.
You own your data. You don't give up your privacy. You are in control.

> [!IMPORTANT]
> Grafida is focused on content _authoring_, not content management. It only provides an essential subset of features compared to the full Joomla! article editor page.

> [!WARNING]
> As of Joomla 5.4.8 and 6.1.3, Joomla's API refuses to create or edit content for users without the "Access Administration Interface" permission, even if they can do the same on the front-end. Only Managers, Administrators and Super Users can use Grafida without giving users back-end access, which has security implications. See [Custom API access](https://grafida.app/docs/desktop/Custom-API-Access.html).

## License

Grafida is free software, licensed under the **GNU General Public License version 3, or
later**. See [LICENSE.txt](LICENSE.txt).

## Further information

* [Key features](assets/docs/key-features.md)
* [Requirements](assets/docs/requirements.md)
* [Basic Usage](assets/docs/usage.md)
* [Out of scope features](assets/docs/out-of-scope.md)

## Developer documentation

* [Build environment](assets/docs/build-environment.md)
* [Build from source](assets/docs/build.md)
* [Automated testing](assets/docs/testing.md)
* [Code signing](assets/docs/code-signing.md)
* [Statement on the use of AI](assets/docs/ai-use.md)

