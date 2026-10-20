# Custom API access for non-Super-Users

> [!WARNING]
> **As of Joomla 5.4.8 and 6.1.3, Joomla no longer lets users without the "Access Administration Interface" permission create or edit content through the API, and this affects Grafida.** In practice, only Managers, Administrators and Super Users can use Grafida without weakening your site's security. Authors, Editors and Publishers cannot, unless you give them back-end access, which you should not do lightly. Read [Joomla 5.4.8 and 6.1.3 and later](#joomla-548-and-613-and-later) below before you follow the instructions on this page.

By default, Joomla only allows API access to and mints API tokens for Super Users. If you want to use a non-Super-User account with Grafida you will need to follow the guide below to configure your site and Grafida itself. Per the warning above, these instructions only make sense for accounts that are Managers or higher.

First, we need to create a special group called API Access. This will allow us to give Joomla API application access to non-Super-User accounts. This must be carried out be a Super User.

1. Log into your site as a Super User.
1. Go to Users Groups and create a new user group with the name **API Access**. Make sure the Group Parent is set to Public.
1. Go to System, Setup, Global Configuration.
1. Click the Permissions tab.
1. Click on the API Access user group.
1. Set the following permissions:
   * **Web Services Login**: Allowed.

Now, we need to tell Joomla it is allowed to mint API tokens for our API Access user group. Again, this must be carried out by a Super User.

1. Log into your site as a Super User.
2. Go to System, Manage, Plugins. 
3. Search for the "User - Joomla API Token" plugin.
4. Click on the plugin
5. In the "Allowed User Groups" section add our "API Access" user group.
6. Click on Save & Close.

You can now edit your non-Super-User user accounts who need access to Grafida and add them to the API Access user group _in addition to_ their normal user group (e.g. Author). 

Your users will now be able to log into your site, go to their profile, edit it, and enable (and see) their Joomla! API Token. They can use that token in Grafida.

## Joomla 5.4.8 and 6.1.3 and later

Joomla's API application uses one base controller (`ApiController`) for every component, in Joomla itself and in third party extensions. Since Joomla 5.4.8 and 6.1.3 it refuses to create, edit or delete **any** record unless the user also has the **Access Administration Interface** (`core.manage`) permission on that component, on top of the usual Create, Edit or Delete permission. Before that, Create, Edit or Delete was enough.

This applies even when the same user can do the same thing without that permission through the site's front-end. An Author who can submit an article from a front-end form now gets an _Access Denied_ error when Grafida creates the very same article through the API.

Granting Access Administration Interface is **not a safe workaround**. It lets the user into the Joomla back-end, and back-end access comes with non-obvious security considerations: component screens, options and tools that were never designed for, or reviewed against, a low-privilege user. This is a Joomla design decision, not something Grafida can work around, and it is not a Grafida bug.

What this means for you:

* **Super Users, Administrators and Managers** can use Grafida. They already have back-end access, so nothing changes for them.
* **Authors, Editors, Publishers and any other group without back-end access cannot use Grafida** on a site running Joomla 5.4.8 / 6.1.3 or later, and the instructions on this page do not fix that. They will see _Access Denied_ when they publish.
* If you decide to accept the risk and grant Access Administration Interface to such a group anyway, set it per component (Articles, Tags, Fields and Media, as needed) and no wider. You do so at your own risk.

If you run an older Joomla release, the instructions above are enough for Authors and above, but you are strongly encouraged to update your site: those releases contain security fixes.

## Caveats

Make sure the Public user group's permissions are all set to the value "Not Set". If you have set anything to "Denied", congratulations, you have broken Joomla's access control model and with it your site. Go fix it before doing anything else.

You must have a login module / menu item, and a menu item for the user's Profile or Edit Profile page on your site's frontend. This is necessary for non-Super-User accounts to be able to create and view their API Token. You CANNOT create or view API Tokens for other user accounts, even if you are a Super User; this is a security feature in Joomla itself. You can always use a "hidden" menu item to do that, and give your users the direct link to the login and profile edit pages on the public site even though these URLs are not linked to from any page of your site. 

Your actions are limited by the user groups your user belongs to _in addition to_ the API Access user group. Authors, for example, cannot change the publish status of an article; only Publishers and above can. Authors cannot edit an existing article either; clicking Grafida's Publish button on a draft of an article already published on the site will result in an Access Denied error toast. This means that you will perceive some features in Grafida "not working". They work fine; Joomla simply denied you access because your Permissions do not allow what you're trying to do. We cannot prevent the display of these features; the Joomla API does not return adequate information for us to determine your access to the site at the granular level required to decide which features to hide.

Grafida cannot get the Unicode Aliases setting of your site. The value of this setting is only available to Super Users. This is not a bug, it's a limitation in Joomla itself. Because Grafida cannot ask, it assumes the setting is off — which is Joomla's own default, and wrong for your site if you have turned it on; the alias generated from a title with non-ASCII characters would then come out partial, or nonsensical.

Tell Grafida which it is instead of working around it: the site's **Site uses Unicode Aliases** setting takes **Yes** and **No** as well as _Automatic_. See [Aliases and transliteration](Aliases-and-transliteration).
