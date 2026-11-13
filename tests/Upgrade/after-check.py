#!/usr/bin/env python3
# Grafida — Joomla content editing, untethered.
#
# Copyright (c) 2026 Nicholas K. Dionysopoulos
# GNU General Public License version 3, or later
#
# The upgrade test's "after" checks (gh-92): run against Grafida 0.4 started over the data that
# seed-0.3.php wrote, through the debug shell's automation channel. See tests/Upgrade/README.md.
#
#   after-check.py MANIFEST AUTOMATION_PY [--token-from-store CMD]
#
# MANIFEST is the seed's JSON; AUTOMATION_PY is grafida/shell's scripts/dev/automation.py, talking to
# a 0.4 shell started with --automation on the same user's data. --token-from-store names a command
# that prints the stored site token for a reference passed as its last argument (macOS:
# 'security find-generic-password -s Grafida -w -a'), so the secret the site uses is compared too.
# Prints one PASS/FAIL line per check and exits 1 if any failed.

import json
import shlex
import subprocess
import sys

if len(sys.argv) < 3:
    sys.exit(__doc__ if __doc__ else 'usage: after-check.py MANIFEST AUTOMATION_PY [--token-from-store CMD]')

manifest = json.load(open(sys.argv[1], encoding='utf-8'))
automation = sys.argv[2]
token_cmd = None
if len(sys.argv) >= 5 and sys.argv[3] == '--token-from-store':
    token_cmd = shlex.split(sys.argv[4])

rows = []


def record(ok, name, detail):
    rows.append((ok, name, detail))
    print(('PASS ' if ok else 'FAIL ') + name + ' — ' + detail, flush=True)


def ev(js, timeout=60):
    out = subprocess.run([sys.executable, '-I', automation, 'eval', js], capture_output=True, text=True, timeout=timeout)
    try:
        answer = json.loads(out.stdout)
    except ValueError:
        raise RuntimeError('automation answered %r %r' % (out.stdout, out.stderr))
    if not answer.get('ok'):
        raise RuntimeError('automation error: %r' % answer)
    return answer.get('result')


def api(path):
    return ev('const r = await fetch(%s); return {status: r.status, json: await r.json()};' % json.dumps('shell://app' + path))


site = manifest['site']
prefs = manifest['preferences']

# 1. The app is 0.4, and it sees 0.3's site, preferences and AI service.
boot = api('/api/bootstrap')
data = boot['json'].get('data') or {}
version = (data.get('app') or {}).get('version')
record(boot['status'] == 200 and version == '0.4', 'The 0.4 worker answers', 'bootstrap %s, version %s' % (boot['status'], version))

sites = {s.get('id'): s for s in data.get('sites') or []}
s = sites.get(site['id']) or {}
record(s.get('title') == site['title'] and s.get('mediaAdapter') == site['mediaAdapter'] and s.get('mediaFolder') == site['mediaFolder']
       and s.get('unicodeAliases') == site['unicodeAliases'],
       'The site survives', 'id %s: %r, media %s:%s, unicode aliases %s' % (site['id'], s.get('title'), s.get('mediaAdapter'), s.get('mediaFolder'), s.get('unicodeAliases')))

got = {'language': data.get('languageOverride'), 'displayMode': data.get('displayMode'), 'slashTools': data.get('slashTools'),
       'spellCheck': data.get('spellCheck'), 'autoCloseTags': data.get('autoCloseTags'),
       'contentNormalisation': data.get('contentNormalisation'), 'lastSiteId': data.get('lastSiteId')}
record(got == prefs, 'The preferences survive', json.dumps(got, ensure_ascii=False))

# 2. The secrets: the AI key comes back through the worker; the site token is read from the store.
ai = manifest['ai']
resolved = api('/api/ai/services/%s/resolved' % ai['id'])
rdata = resolved['json'].get('data') or {}
record(resolved['status'] == 200 and rdata.get('apiKey') == ai['key'] and rdata.get('endpoint', '').startswith(ai['endpoint'].rstrip('/')),
       'The AI service and its key survive', 'resolved %s, endpoint %s, key %s' % (resolved['status'], rdata.get('endpoint'), 'matches' if rdata.get('apiKey') == ai['key'] else 'DIFFERS'))

if token_cmd:
    out = subprocess.run(token_cmd + [site['secretRef']], capture_output=True, text=True)
    stored = out.stdout.rstrip('\r\n')
    record(out.returncode == 0 and stored == site['token'], 'The site token survives in the OS store',
           'reference %s: %s' % (site['secretRef'], 'matches' if stored == site['token'] else 'exit %s, DIFFERS' % out.returncode))

# 3. The drafts, byte for byte, and their pictures, by content.
listed = api('/api/sites/%s/drafts' % site['id'])
titles = sorted(d.get('title') for d in (listed['json'].get('data') or []))
record(titles == sorted(d['title'] for d in manifest['drafts']), 'The drafts are listed', ', '.join(map(str, titles)))

for d in manifest['drafts']:
    got = api('/api/drafts/%s' % d['id'])
    html = (got['json'].get('data') or {}).get('html')
    # 0.3 wrote boson:// media references; migration 11 rewrites exactly that prefix and nothing else.
    expected = (d['html'] or '').replace('boson://app/api/media/', 'shell://app/api/media/')
    record(got['status'] == 200 and html == expected, 'Draft %s is unchanged (boson:// -> shell://)' % d['id'],
           '%d characters%s' % (len(html or ''), '' if html == expected else ', DIFFERS'))

# The SHA-256 of seed-0.3.php's two PNGs (a manifest from a seed that predates its `images` key).
want = sorted((manifest.get('images') or {
    'red': '19960f9892fd49faa80bb6953d648e81faa9cf560731e2142651d00f57381ebe',
    'blue': '85f586720753021dc9ba0a784d913dc764624d5799d72c4a87412c48c8457ce5',
}).values())
hashes = ev('''
const out = [];
for (const url of %s) {
    const r = await fetch(url);
    const bytes = await r.arrayBuffer();
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    out.push({status: r.status, type: r.headers.get('content-type'),
              sha: [...new Uint8Array(digest)].map(b => b.toString(16).padStart(2, '0')).join('')});
}
return out;''' % json.dumps([u.replace('boson://', 'shell://') for u in manifest['drafts'][0]['mediaUrls']]))
record(sorted(h['sha'] for h in hashes) == want and all(h['status'] == 200 for h in hashes),
       'The shell:// pictures are served, byte for byte', '; '.join('%s %s %s…' % (h['status'], h['type'], h['sha'][:12]) for h in hashes))

# 4. The editor shows them.
shown = ev('''
const draft = (await (await fetch(%s)).json()).data;
await openDraftInEditor(draft);
for (let i = 0; i < 100; i++) {
    const doc = window.tinymce && tinymce.activeEditor && tinymce.activeEditor.getDoc();
    const imgs = doc ? [...doc.querySelectorAll('img')] : [];
    if (imgs.length === 2 && imgs.every(img => img.complete && img.naturalWidth > 0)) {
        return imgs.map(img => ({src: img.getAttribute('src'), size: img.naturalWidth + 'x' + img.naturalHeight}));
    }
    await new Promise(r => setTimeout(r, 100));
}
const doc = window.tinymce && tinymce.activeEditor && tinymce.activeEditor.getDoc();
return {timeout: true, imgs: doc ? [...doc.querySelectorAll('img')].map(i => [i.getAttribute('src'), i.complete, i.naturalWidth]) : null};
''' % json.dumps('shell://app/api/drafts/%s' % manifest['drafts'][0]['id']), timeout=90)
ok = isinstance(shown, list) and sorted(i['size'] for i in shown) == ['32x48', '48x32']
record(ok, 'The editor renders both pictures', json.dumps(shown))

failed = [r for r in rows if not r[0]]
print('\n%d passed, %d failed' % (len(rows) - len(failed), len(failed)))
sys.exit(1 if failed else 0)
