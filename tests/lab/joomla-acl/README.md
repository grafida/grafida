# Joomla API permissions lab

Can a user who is **not** a Super User publish with Grafida, and what do they have to be given? Since
Joomla 5.4.8 / 6.1.3, `ApiController` refuses every create, edit and delete unless the user also has
**Access Administration Interface** (`core.manage`) on the component. This lab measures what that
means on a real site instead of reasoning about it from the source.

```bash
./run.sh 5.4 6.1 6.2        # newest stable release of each, one after the other
./run.sh --keep 6.2         # leave the last site running at http://127.0.0.1:18620
```

Exit status 0 means every check matched its expectation. A `FAIL` line means a Joomla release changed
behaviour Grafida or its documentation relies on — read it before changing the expectation. `NOTE`
lines are recorded, not judged.

With `--keep`, the Super User is `admin` / `Lab-Admin-Passw0rd!` and every test user's password is
`Lab-User-Passw0rd!`. Tear down with `docker compose -f docker-compose.yml down -v && rm -rf www`.

## The groups

| Group | Parent | Permissions | User |
|---|---|---|---|
| Lab API Login Only | Author | API Login (Global Configuration) — the recipe documented before 5.4.8 | `base` |
| Lab API Access | Public | API Login + Access Administration Interface on Articles, Media and Tags (+ Edit Custom Field Value on Articles, granted mid-run) — **the documented recipe**, laid out as documented: its user is in Author *and* this group | `authora` |
| Lab Config B | Author | Lab API Access's rights + Administrator Login | `authorb` |
| Lab Category Scoped | Registered | Lab API Access's component rights, Create + Edit Own on category 2 only | `scoped` |

Every group is added to the *User – Joomla API Token* plugin's allowed groups. Permissions are written
straight into `#__assets` by `lib/acl.php`, tokens minted by `lib/token.php`; neither needs a browser.

## What it found (identical on 5.4.10, 6.1.4 and 6.2.0)

- **API Login alone no longer lets anyone write**: every create/edit/delete answers 403.
- **The documented recipe (`run.sh` calls it config A) works without back-end access.** Access
  Administration Interface and Administrator Login are separate permissions; its user is refused at `/administrator` ("You do not have access to
  the Administrator section of this site.") while config B's, with the same script, logs in.
- **What its user cannot do**: publish (state=1 is saved as 0 with a 200 and no error), overwrite,
  rename or delete media (needs Edit/Delete on com_media, which is site-wide), edit or delete anyone
  else's article, category or field. Tags it creates are saved unpublished.
- **Custom field values are dropped silently** unless the group has *Edit Custom Field Value* — the
  same rule Joomla applies to a front-end Author.
- **What its user can do beyond the front-end**: create article categories and custom fields (both
  saved unpublished). `CategoriesController` and `FieldsController` check the same Access
  Administration Interface + Create on `com_content` an article does, so this cannot be split off.
- **Category-level Create is not enforced over the API.** `ApiController::allowAdd()` only asks whether
  the user may create in *some* category; nothing checks the posted `catid`, so the scoped user created
  an article in a category they have no rights to. Scoping to categories still blocks custom-field
  creation, which has no per-category fallback.
- **Config B** (Administrator Login added) reaches the Articles, Article Categories, Article Fields,
  Media and Tags managers; Users, Global Configuration, Plugins, Extensions, Modules and Templates
  answer 403.
- Two things every user hits, Super User included: a tag sent without `parent_id` is refused ("Invalid
  field: Parent"), and — on a strict-SQL-mode database — without `description`. And a user without
  Edit State gets a bare HTTP 500 editing an article that was ever published, unless the PATCH carries
  `publish_up` / `publish_down` as `null` (which keeps the stored dates, for a Super User too).
