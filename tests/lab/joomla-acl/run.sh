#!/usr/bin/env bash
#
# Grafida — Joomla content editing, untethered.
#
# @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
# @license   GNU General Public License version 3, or later
#
# Joomla API permissions lab: what can a NON-Super-User do to a real Joomla site through the API
# Grafida uses? See README.md for the questions it answers and how to read the output.
#
# Usage:
#   ./run.sh [--keep] [VERSION ...]
#
#   VERSION   A Joomla release or prefix: 6.2, 6.1, 5.4, 6.2.0 … (default: 6.2). A prefix resolves to
#             the newest stable release on GitHub. Several versions run one after the other.
#   --keep    Leave the last version's stack running at http://127.0.0.1:$HTTP_PORT for poking at.
#
# Environment: HTTP_PORT (18620), PHP_VERSION (8.4), APACHE_VERSION (2.4), MARIADB_VERSION (11).
#
# Requires docker, curl, jq, unzip and the akeeba/buildfiles checkout at ../buildfiles.
# Exit status: 0 when every check matched its expectation, 1 otherwise.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${HERE}/../../.." && pwd)"
E2E_IMAGES="${REPO_ROOT}/../buildfiles/docker/e2e/images.sh"
INBOX="${HERE}/inbox"
WWW="${HERE}/www"

export HTTP_PORT="${HTTP_PORT:-18620}"
export PHP_VERSION="${PHP_VERSION:-8.4}"
export APACHE_VERSION="${APACHE_VERSION:-2.4}"
export MARIADB_VERSION="${MARIADB_VERSION:-11}"

SITE="http://127.0.0.1:${HTTP_PORT}"
API="${SITE}/api/index.php/v1"
ADMIN_PASSWORD='Lab-Admin-Passw0rd!'
USER_PASSWORD='Lab-User-Passw0rd!'

KEEP=0
VERSIONS=()
for arg in "$@"; do
	case "${arg}" in
		--keep) KEEP=1 ;;
		-h|--help) sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) VERSIONS+=("${arg}") ;;
	esac
done
[ "${#VERSIONS[@]}" -eq 0 ] && VERSIONS=(6.2)

log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 2; }

DC() { docker compose -f "${HERE}/docker-compose.yml" "$@"; }
php_cli() { DC exec -T -w /var/www/html php php "$@"; }
sql() { DC exec -T db mariadb -ulab -plab lab -N -e "$1"; }
acl() { php_cli /dev/stdin "$@" < "${HERE}/lib/acl.php"; }
mint_token() { php_cli /dev/stdin "$1" < "${HERE}/lib/token.php"; }

teardown() {
	DC down -v --remove-orphans >/dev/null 2>&1 || true
	rm -rf "${WWW}"
}

on_exit() {
	if [ "${KEEP}" -eq 1 ] && [ -d "${WWW}" ]; then
		log "Leaving the stack running at ${SITE} (admin / ${ADMIN_PASSWORD})."
		echo "Tear it down with: docker compose -f ${HERE}/docker-compose.yml down -v && rm -rf ${WWW}"
	else
		teardown
	fi
}
trap on_exit EXIT

for tool in docker curl jq unzip; do
	command -v "${tool}" >/dev/null 2>&1 || die "${tool} is required."
done

# ---------------------------------------------------------------------------------------------------
# Joomla acquisition
# ---------------------------------------------------------------------------------------------------

# Newest stable GitHub release matching a prefix ("6.2" → "6.2.0"; "6.2.0" → itself).
resolve_version() {
	local prefix="$1"
	curl -fsSL "https://api.github.com/repos/joomla/joomla-cms/releases?per_page=100" \
		| jq -r --arg p "${prefix}" '[.[] | select(.prerelease | not) | .tag_name
			| select(. == $p or startswith($p + "."))] | first // empty'
}

fetch_joomla() {
	local version="$1" zip="${INBOX}/Joomla_${1}-Stable-Full_Package.zip"
	mkdir -p "${INBOX}"
	if [ ! -f "${zip}" ]; then
		curl -fsSL -o "${zip}.part" \
			"https://github.com/joomla/joomla-cms/releases/download/${version}/Joomla_${version}-Stable-Full_Package.zip" \
			|| die "Could not download Joomla ${version}."
		mv "${zip}.part" "${zip}"
	fi
	echo "${zip}"
}

# ---------------------------------------------------------------------------------------------------
# HTTP helpers. api() leaves the status in CODE and the body in BODY.
# ---------------------------------------------------------------------------------------------------

CODE=""
BODY=""
api() {
	local token="$1" method="$2" path="$3" data="${4:-}" out
	local -a args=(-s -w '\n%{http_code}' -X "${method}" -H "X-Joomla-Token: ${token}"
		-H 'Accept: application/vnd.api+json')
	[ -n "${data}" ] && args+=(-H 'Content-Type: application/json' --data "${data}")
	out="$(curl "${args[@]}" "${API}/${path}")"
	CODE="$(printf '%s' "${out}" | tail -n1)"
	BODY="$(printf '%s' "${out}" | sed '$d')"
}

# A field of the last response, e.g. jget '.data.attributes.state'.
jget() { printf '%s' "${BODY}" | jq -r "$1" 2>/dev/null || true; }

# First error title/detail of the last response, trimmed to the message.
jerr() { jget '.errors[0].detail // .errors[0].title // empty' | sed 's# in /var/www.*##' | head -c 160; }

# Logs into /administrator with a password; sets LOGIN to "in" or "out" and ADMIN_JAR to the session.
# Not called through $(…): a subshell would lose ADMIN_JAR.
LOGIN=""
ADMIN_JAR=""
admin_login() {
	local username="$1" jar page tok home
	jar="$(mktemp)"
	page="$(curl -s -c "${jar}" -b "${jar}" "${SITE}/administrator/index.php")"
	tok="$(printf '%s' "${page}" | grep -oE 'name="[0-9a-f]{32}" value="1"' | head -1 | cut -d'"' -f2)"
	curl -s -c "${jar}" -b "${jar}" -o /dev/null "${SITE}/administrator/index.php" \
		--data-urlencode "username=${username}" --data-urlencode "passwd=${USER_PASSWORD}" \
		-d "option=com_login&task=login&return=aW5kZXgucGhw&${tok}=1"
	home="$(curl -s -c "${jar}" -b "${jar}" "${SITE}/administrator/index.php")"
	ADMIN_JAR="${jar}"
	if printf '%s' "${home}" | grep -q 'task=logout'; then LOGIN=in; else LOGIN=out; fi
}

# HTTP status of a back-end page for the session admin_login() left in ADMIN_JAR.
admin_page() { curl -s -o /dev/null -w '%{http_code}' -c "${ADMIN_JAR}" -b "${ADMIN_JAR}" "${SITE}/administrator/index.php?$1"; }

# ---------------------------------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------------------------------

PASS=0
FAIL=0
FAILED=()
# check "<description>" "<expected>" "<actual>"
check() {
	if [ "$2" = "$3" ]; then
		PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m  %-78s %s\n' "$1" "$3"
	else
		FAIL=$((FAIL + 1)); FAILED+=("${JOOMLA}: $1 (expected $2, got $3)")
		printf '  \033[31mFAIL\033[0m  %-78s expected %s, got %s\n' "$1" "$2" "$3"
	fi
}
# note "<description>" "<value>": recorded, not judged.
note() { printf '  \033[36mNOTE\033[0m  %-78s %s\n' "$1" "$2"; }

PNG='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='

article_json() { # title alias catid state [extra-json-members]
	printf '{"title":"%s","alias":"%s","catid":%s,"access":1,"state":%s,"language":"*","introtext":"<p>Intro</p>","fulltext":"","created_by_alias":""%s}' \
		"$1" "$2" "$3" "$4" "${5:+,$5}"
}

run_version() {
	local requested="$1"
	JOOMLA="$(resolve_version "${requested}")"
	[ -n "${JOOMLA}" ] || die "No stable Joomla release matches '${requested}'."

	log "Joomla ${JOOMLA}: provisioning"
	local zip; zip="$(fetch_joomla "${JOOMLA}")"
	teardown
	mkdir -p "${WWW}"
	unzip -q -o "${zip}" -d "${WWW}"

	if [ -x "${E2E_IMAGES}" ]; then
		"${E2E_IMAGES}" ensure php "${PHP_VERSION}" >/dev/null
		"${E2E_IMAGES}" ensure web "${APACHE_VERSION}" >/dev/null
	fi
	DC up -d --wait >/dev/null 2>&1 || DC up -d >/dev/null

	php_cli installation/joomla.php install --site-name "ACL Lab" --admin-user "Super User" \
		--admin-username admin --admin-password "${ADMIN_PASSWORD}" --admin-email admin@example.com \
		--db-type mysqli --db-host db --db-user lab --db-pass lab --db-name lab --db-prefix jos_ \
		--db-encryption 0 --public-folder "" >/dev/null
	rm -rf "${WWW}/installation"

	local ADMIN_ID ADMIN
	ADMIN_ID="$(sql "SELECT id FROM jos_users WHERE username = 'admin'")"
	ADMIN="$(mint_token "${ADMIN_ID}")"

	# Groups:
	#   BASE  child of Author: the pre-5.4.8 recipe, API Login only
	#   A     child of Public: the recipe docs/Custom-API-Access.md documents, laid out the way it
	#         documents it — API Login + Access Administration Interface on Articles, Media and Tags,
	#         given to a user who is in Author AS WELL (Joomla combines the groups' permissions)
	#   B     child of Author: A's rights + Administrator Login
	#   CATS  child of Registered: A's component rights, but Create / Edit Own on ONE category only
	local BASE A B CATS g
	for g in "Lab API Login Only:3" "Lab API Access:1" "Lab Config B:3" "Lab Category Scoped:2"; do
		api "${ADMIN}" POST users/groups "{\"title\":\"${g%%:*}\",\"parent_id\":${g##*:}}"
		[ "${CODE}" = 200 ] || die "Could not create group ${g%%:*}: $(jerr)"
	done
	BASE="$(sql "SELECT id FROM jos_usergroups WHERE title = 'Lab API Login Only'")"
	A="$(sql "SELECT id FROM jos_usergroups WHERE title = 'Lab API Access'")"
	B="$(sql "SELECT id FROM jos_usergroups WHERE title = 'Lab Config B'")"
	CATS="$(sql "SELECT id FROM jos_usergroups WHERE title = 'Lab Category Scoped'")"

	local u
	for u in "base:Lab API Login Only" "authora:Author,Lab API Access" "authorb:Lab Config B" "scoped:Lab Category Scoped"; do
		php_cli cli/joomla.php user:add -n --username "${u%%:*}" --name "${u%%:*}" \
			--password "${USER_PASSWORD}" --email "${u%%:*}@example.com" --usergroup "${u#*:}" >/dev/null
	done

	for g in "${BASE}" "${A}" "${B}" "${CATS}"; do acl root.1 core.login.api "${g}" 1; done
	acl root.1 core.login.admin "${B}" 1
	local c
	for c in com_content com_media com_tags; do
		for g in "${A}" "${B}" "${CATS}"; do acl "${c}" core.manage "${g}" 1; done
	done
	acl com_media core.create "${CATS}" 1
	acl com_tags core.create "${CATS}" 1
	acl com_content.category.2 core.create "${CATS}" 1
	acl com_content.category.2 core.edit.own "${CATS}" 1
	sql "UPDATE jos_extensions SET params = JSON_SET(IF(params = '' OR params IS NULL, '{}', params),
		'\$.allowedUserGroups', JSON_ARRAY('8','${BASE}','${A}','${B}','${CATS}'))
		WHERE type = 'plugin' AND folder = 'user' AND element = 'token'"

	local T_BASE T_A T_B T_CATS
	T_BASE="$(mint_token "$(sql "SELECT id FROM jos_users WHERE username = 'base'")")"
	T_A="$(mint_token "$(sql "SELECT id FROM jos_users WHERE username = 'authora'")")"
	T_B="$(mint_token "$(sql "SELECT id FROM jos_users WHERE username = 'authorb'")")"
	T_CATS="$(mint_token "$(sql "SELECT id FROM jos_users WHERE username = 'scoped'")")"

	# Fixtures owned by the Super User: a published article, a second category, a text custom field.
	api "${ADMIN}" POST content/articles "$(article_json 'Admin article' admin-article 2 1)"
	local FOREIGN; FOREIGN="$(jget '.data.id')"
	api "${ADMIN}" POST content/categories '{"title":"Admin Cat","extension":"com_content","parent_id":1,"published":1,"language":"*","description":""}'
	local CAT2; CAT2="$(jget '.data.id')"
	api "${ADMIN}" POST fields/content/articles '{"title":"Subtitle","name":"subtitle","label":"Subtitle","type":"text","context":"com_content.article","state":1,"language":"*","description":"","default_value":"","group_id":0,"assigned_cat_ids":[],"params":{"hint":""},"fieldparams":{"filter":""},"note":""}'
	local FIELD; FIELD="$(jget '.data.id')"
	[ -n "${FOREIGN}" ] && [ -n "${CAT2}" ] && [ -n "${FIELD}" ] || die "Could not create the Super User fixtures."

	log "Joomla ${JOOMLA}: API Login only (the recipe documented before 5.4.8)"
	api "${T_BASE}" GET content/articles;                check "read articles" 200 "${CODE}"
	api "${T_BASE}" POST content/articles "$(article_json 'Base' base 2 0)"
	check "create an article" 403 "${CODE}"
	api "${T_BASE}" POST media/files "{\"path\":\"local-images:/grafida/base.png\",\"content\":\"${PNG}\"}"
	check "upload an image" 403 "${CODE}"

	log "Joomla ${JOOMLA}: config A — Author + API Access (Access Administration Interface on Articles/Media/Tags), no Administrator Login"
	local p
	for p in content/articles 'content/categories?filter%5Bextension%5D=com_content' tags users/levels \
		languages/content fields/content/articles media/adapters templates/styles/site; do
		api "${T_A}" GET "${p}"; check "read ${p%%\?*}" 200 "${CODE}"
	done
	api "${T_A}" GET 'config/application?page%5Boffset%5D=0&page%5Blimit%5D=20'
	check "read config/application (needs core.admin)" 403 "${CODE}"

	api "${T_A}" POST content/articles "$(article_json 'Own draft' own-draft 2 0)"
	check "create an article" 200 "${CODE}"
	local OWN; OWN="$(jget '.data.id')"
	api "${T_A}" PATCH "content/articles/${OWN}" '{"introtext":"<p>Edited</p>","fulltext":""}'
	check "edit own unpublished article" 200 "${CODE}"
	api "${T_A}" POST content/articles "$(article_json 'Asked to publish' asked-to-publish 2 1)"
	check "create with state=1: request succeeds" 200 "${CODE}"
	check "create with state=1: saved unpublished, no error" 0 "$(jget '.data.attributes.state')"
	api "${T_A}" PATCH "content/articles/${FOREIGN}" '{"introtext":"<p>hijacked</p>","fulltext":""}'
	check "edit someone else's article" 403 "${CODE}"
	api "${T_A}" DELETE "content/articles/${FOREIGN}"
	check "delete someone else's article" 403 "${CODE}"

	api "${T_A}" POST media/files "{\"path\":\"local-images:/grafida/a.png\",\"content\":\"${PNG}\"}"
	check "upload an image" 200 "${CODE}"
	api "${T_A}" PATCH "media/files/local-images:/grafida/a.png" "{\"path\":\"local-images:/grafida/a.png\",\"content\":\"${PNG}\",\"override\":true}"
	check "overwrite own upload (image editor save; needs Edit on com_media)" 403 "${CODE}"
	api "${T_A}" PATCH "media/files/local-images:/grafida/a.png" '{"path":"local-images:/grafida/renamed.png","override":false}'
	check "rename own upload" 403 "${CODE}"

	api "${T_A}" POST tags '{"title":"tag-a-grafida-payload","published":1,"language":"*"}'
	check "create a tag with the pre-fix Grafida payload (no parent_id)" 400 "${CODE}"
	note "  … error" "$(jerr)"
	api "${T_A}" POST tags '{"title":"tag-a","parent_id":1,"description":"","published":1,"language":"*"}'
	check "create a tag with parent_id + description" 200 "${CODE}"
	check "  … saved unpublished (no Edit State)" 0 "$(jget '.data.attributes.published')"

	api "${T_A}" POST content/categories '{"title":"Author Cat","extension":"com_content","parent_id":1,"published":1,"language":"*","description":""}'
	check "create an article category (widening)" 200 "${CODE}"
	check "  … saved unpublished" 0 "$(jget '.data.attributes.published')"
	api "${T_A}" POST fields/content/articles '{"title":"Author Field","name":"authorfield","label":"Author Field","type":"text","context":"com_content.article","state":1,"language":"*","description":"","default_value":"","group_id":0,"assigned_cat_ids":[],"params":{"hint":""},"fieldparams":{"filter":""},"note":""}'
	check "create a custom field (widening)" 200 "${CODE}"
	api "${T_A}" PATCH "content/categories/${CAT2}" '{"title":"hijacked"}'
	check "edit someone else's category" 403 "${CODE}"
	api "${T_A}" PATCH "fields/content/articles/${FIELD}" '{"label":"hijacked"}'
	check "edit someone else's custom field" 403 "${CODE}"

	api "${T_A}" PATCH "content/articles/${OWN}" '{"introtext":"<p>With field</p>","fulltext":"","com_fields":{"subtitle":"value-1"}}'
	check "custom field value without Edit Custom Field Value: request" 200 "${CODE}"
	check "  … value silently dropped" "" "$(sql "SELECT value FROM jos_fields_values WHERE field_id = ${FIELD} AND item_id = '${OWN}'")"
	acl com_content core.edit.value "${A}" 1
	api "${T_A}" PATCH "content/articles/${OWN}" '{"introtext":"<p>With field</p>","fulltext":"","com_fields":{"subtitle":"value-2"}}'
	check "custom field value with Edit Custom Field Value on Articles" value-2 "$(sql "SELECT value FROM jos_fields_values WHERE field_id = ${FIELD} AND item_id = '${OWN}'")"

	log "Joomla ${JOOMLA}: editing an article that has been published, without Edit State"
	api "${ADMIN}" PATCH "content/articles/${OWN}" '{"state":1,"publish_down":"2031-01-01 00:00:00"}'
	local UP DOWN
	UP="$(sql "SELECT publish_up FROM jos_content WHERE id = ${OWN}")"
	DOWN="$(sql "SELECT publish_down FROM jos_content WHERE id = ${OWN}")"
	api "${T_A}" PATCH "content/articles/${OWN}" "$(article_json 'Own draft' own-draft 2 1)"
	check "Grafida-style PATCH (dates omitted, backfilled by Joomla)" 500 "${CODE}"
	note "  … error" "$(jerr)"
	api "${T_A}" PATCH "content/articles/${OWN}" "$(article_json 'Own draft' own-draft 2 1 '"publish_up":null,"publish_down":null')"
	check "same PATCH with publish_up/publish_down null" 200 "${CODE}"
	check "  … publish_up kept" "${UP}" "$(sql "SELECT publish_up FROM jos_content WHERE id = ${OWN}")"
	check "  … publish_down kept" "${DOWN}" "$(sql "SELECT publish_down FROM jos_content WHERE id = ${OWN}")"
	check "  … still published" 1 "$(sql "SELECT state FROM jos_content WHERE id = ${OWN}")"

	api "${ADMIN}" PATCH "content/articles/${FOREIGN}" '{"publish_down":"2032-01-01 00:00:00"}'
	UP="$(sql "SELECT publish_up FROM jos_content WHERE id = ${FOREIGN}")"
	DOWN="$(sql "SELECT publish_down FROM jos_content WHERE id = ${FOREIGN}")"
	api "${ADMIN}" PATCH "content/articles/${FOREIGN}" "$(article_json 'Admin article' admin-article 2 1 '"publish_up":null,"publish_down":null')"
	check "Super User PATCH with null dates" 200 "${CODE}"
	check "  … publish_up kept" "${UP}" "$(sql "SELECT publish_up FROM jos_content WHERE id = ${FOREIGN}")"
	check "  … publish_down kept" "${DOWN}" "$(sql "SELECT publish_down FROM jos_content WHERE id = ${FOREIGN}")"

	log "Joomla ${JOOMLA}: back-end login"
	admin_login authora; check "config A: Administrator login refused" out "${LOGIN}"
	admin_login authorb; check "config B: Administrator login allowed" in "${LOGIN}"
	for p in 'option=com_content&view=articles' 'option=com_categories&view=categories&extension=com_content' \
		'option=com_fields&view=fields&context=com_content.article' 'option=com_media' 'option=com_tags&view=tags'; do
		check "config B reaches ${p}" 200 "$(admin_page "${p}")"
	done
	for p in 'option=com_users&view=users' 'option=com_config' 'option=com_plugins&view=plugins' \
		'option=com_installer&view=manage' 'option=com_modules' 'option=com_templates'; do
		check "config B refused ${p}" 403 "$(admin_page "${p}")"
	done

	log "Joomla ${JOOMLA}: create rights on one category only (child of Registered)"
	api "${T_CATS}" POST content/articles "$(article_json 'Scoped in 2' scoped-2 2 0)"
	check "create an article in the permitted category" 200 "${CODE}"
	api "${T_CATS}" POST content/articles "$(article_json 'Scoped elsewhere' scoped-elsewhere "${CAT2}" 0)"
	check "create an article in a category WITHOUT create permission" 200 "${CODE}"
	note "  … landed in category" "$(jget '.data.relationships.category.data.id // .data.attributes.catid // "?"')"
	api "${T_CATS}" POST content/categories '{"title":"Scoped Cat","extension":"com_content","parent_id":1,"published":1,"language":"*","description":""}'
	check "create an article category" 200 "${CODE}"
	api "${T_CATS}" POST fields/content/articles '{"title":"Scoped Field","name":"scopedfield","label":"Scoped Field","type":"text","context":"com_content.article","state":1,"language":"*","description":"","default_value":"","group_id":0,"assigned_cat_ids":[],"params":{"hint":""},"fieldparams":{"filter":""},"note":""}'
	check "create a custom field (no per-category fallback)" 403 "${CODE}"
}

for v in "${VERSIONS[@]}"; do
	run_version "${v}"
done

log "Summary: ${PASS} passed, ${FAIL} failed"
for f in "${FAILED[@]+"${FAILED[@]}"}"; do echo "  - ${f}"; done
[ "${FAIL}" -eq 0 ]
