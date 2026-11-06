# Custom API access for non-Super-Users

By default, Joomla only allows API access to and mints API tokens for Super Users. If you want to use a non-Super-User account with Grafida — an Author, for example — you will need to follow the guide below to configure your site. All of it must be carried out by a Super User.

> [!IMPORTANT]
> **Joomla 5.4.8, 6.1.3 and later need one more permission than older releases did.** Their API refuses to create or edit anything unless the user also has **Access Administration Interface** on the component involved. Step 3 below grants it on Articles, Media and Tags only. This does **not** let your users into your site's back-end: that is a different permission, **Administrator Login**, which you leave alone. See [Why Access Administration Interface does not mean back-end access](#why-access-administration-interface-does-not-mean-back-end-access).

## Setting up the API Access group

**Step 1: create the group.**

1. Log into your site's back-end as a Super User.
1. Go to Users, Groups and create a new user group with the name **API Access**. Make sure the Group Parent is set to **Public**.

**Step 2: let the group use the API.**

1. Go to System, Setup, Global Configuration.
1. Click the Permissions tab.
1. Click on the API Access user group.
1. Set **Web Services Login** to _Allowed_.
1. Leave everything else as it is. In particular, do **not** set **Administrator Login** or **Access Administration Interface** here: on this page they would apply to every component on your site, including third-party ones.
1. Click on Save & Close.

**Step 3: let the group write articles, images and tags through the API.** You do this on three components, and only those three. In System, Setup, Global Configuration, the components are listed down the left-hand side.

1. Click on **Articles**, then the Permissions tab, then the API Access user group. Set **Access Administration Interface** and **Edit Custom Field Value** to _Allowed_. Click on Save.
1. Click on **Media**, then the Permissions tab, then the API Access user group. Set **Access Administration Interface** to _Allowed_. Click on Save.
1. Click on **Tags**, then the Permissions tab, then the API Access user group. Set **Access Administration Interface** to _Allowed_. Click on Save & Close.

Without **Edit Custom Field Value**, Joomla quietly discards the values your users give the article's custom fields; the article itself still saves. You can skip Media if your users never add images, and Tags if they never create new tags (picking existing tags needs nothing).

**Step 4: let Joomla give the group API tokens.**

1. Go to System, Manage, Plugins.
1. Search for the "User - Joomla API Token" plugin.
1. Click on the plugin.
1. In the "Allowed User Groups" section add the "API Access" user group.
1. Click on Save & Close.

**Step 5: add your users to the group.** Edit each non-Super-User account that needs Grafida and add it to the API Access user group _in addition to_ its normal user group (e.g. Author). Joomla combines the permissions of all of a user's groups: the normal group says what the user may do with articles, and API Access says they may do it through the API.

Your users will now be able to log into your site, go to their profile, edit it, and enable (and see) their Joomla! API Token. They can use that token in Grafida.

## Why Access Administration Interface does not mean back-end access

Joomla has two different permissions here, and their names make them easy to confuse:

* **Administrator Login** (Global Configuration only) decides whether someone can log into the back-end at all. Without it, Joomla turns them away at the login page with "You do not have access to the Administrator section of this site", whatever else they are allowed.
* **Access Administration Interface** (per component) decides whether someone who _is_ in the back-end may open that component's screens. Since Joomla 5.4.8 and 6.1.3, the API checks it too before it creates, edits or deletes anything.

The API Access group gets the second on three components, and never the first, so your users can publish through Grafida but still cannot log into the back-end. We tested this on Joomla 5.4.10, 6.1.4 and 6.2.0.

Do **not** "fix" anything by giving the group Administrator Login. It is not needed, and it would open the Articles, Categories, Fields, Media and Tags screens of the back-end to those users. That is where back-end access gets risky: back-end screens, and especially third-party back-end extensions, are often written assuming whoever reaches them is trusted.

## What your users can and cannot do

What a user can do is decided by their normal user group. The API Access group only lets them do it through the API. For an **Author**:

* **They can** write new articles, edit their own, upload images, and add existing or new tags. Adding a tag that does not exist on the site yet, and editing an article of theirs that has already been published, need **Grafida 1.0 or later**; earlier versions fail at both.
* **They cannot publish.** Joomla saves their articles as unpublished even if they chose Published in Grafida, and Grafida still reports a successful publish. A Publisher or above has to publish the article. The same goes for new tags they create: Joomla saves those unpublished.
* **They cannot edit anyone else's articles**, nor change a published article's publishing dates.
* **They cannot edit, rename or delete images** already uploaded to the site, including their own. That needs the Edit and Delete permissions on Media, which apply to every file in the Media Manager, not just the user's own. This is why Grafida's Media Manager and image editor cannot save changes to site images for such a user.

There is one thing they **can** do through the API that they cannot do on your site's front-end. With Access Administration Interface and Create on Articles — which they need to write articles at all — Joomla's API also lets them **create article categories and article custom fields**. Joomla saves both unpublished, so neither appears on your site or in the article editor until someone with the right to publish them does so. You cannot switch this off without also stopping them from writing articles, because Joomla checks exactly the same permissions for all three. Keep an eye on unpublished categories and fields, and delete any you did not expect.

If you were thinking of limiting **which categories** an Author can write in through each category's own permissions: the API does not enforce that for new articles. A user who may create articles in any one category can create them in every category through the API. Joomla only uses those permissions to decide which categories its own forms offer.

## Caveats

Make sure the Public user group's permissions are all set to the value "Not Set". If you have set anything to "Denied", congratulations, you have broken Joomla's access control model and with it your site. Go fix it before doing anything else.

You must have a login module / menu item, and a menu item for the user's Profile or Edit Profile page on your site's frontend. This is necessary for non-Super-User accounts to be able to create and view their API Token. You CANNOT create or view API Tokens for other user accounts, even if you are a Super User; this is a security feature in Joomla itself. You can always use a "hidden" menu item to do that, and give your users the direct link to the login and profile edit pages on the public site even though these URLs are not linked to from any page of your site. 

Joomla's API does not tell Grafida what your user is allowed to do, so Grafida cannot hide what you may not use. Something Joomla refuses shows up as an Access Denied error toast, and something Joomla quietly ignores, like publishing as an Author, simply does not take effect. This does not mean the feature is broken; your permissions do not allow it.

Grafida cannot get the Unicode Aliases setting of your site. The value of this setting is only available to Super Users. This is not a bug, it's a limitation in Joomla itself. Because Grafida cannot ask, it assumes the setting is off — which is Joomla's own default, and wrong for your site if you have turned it on; the alias generated from a title with non-ASCII characters would then come out partial, or nonsensical.

Tell Grafida which it is instead of working around it: the site's **Site uses Unicode Aliases** setting takes **Yes** and **No** as well as _Automatic_. See [Aliases and transliteration](Aliases-and-transliteration).
