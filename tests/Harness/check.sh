#!/bin/bash
# Live checks for Switchboard against the shared Craft 6 harness (~/Sites/plugin-testing-craft6).
#
# Self-contained: fixtures.php creates `switchboard`-prefixed sections, fields, a group and users;
# cleanup.php removes them (and every rule, delivery and bell notification) at the end.
# config.php → config/craft/switchboard.php: Switchboard's own `switchboard` queue (only our jobs
# run here) and the local webhook sink (sink.php on 127.0.0.1:18093 inside the web container).
# Multi-site checks create and remove a `switchboardfr` site under bin/harness-lock.
#   SKIP_MULTISITE=1  skip them      SKIP_REINSTALL=1  skip the uninstall/reinstall check
set -uo pipefail
exec < /dev/null
H=/Users/jholt/Sites/plugin-testing-craft6
SRC=$(cd "$(dirname "$0")" && pwd)
U=https://plugin-testing-craft6.ddev.site
W=ddev-plugin-testing-craft6-web
cd "$H" || exit 2
pass=0 fail=0
ok() { if [ "$2" = "$3" ]; then echo "✓ $1"; pass=$((pass+1)); else echo "✗ $1 (got '$2', want '$3')"; fail=$((fail+1)); fi; }
has() { case "$2" in *"$3"*) echo "✓ $1"; pass=$((pass+1));; *) echo "✗ $1 ('$3' not in '${2:0:300}')"; fail=$((fail+1));; esac; }
hasnt() { case "$2" in *"$3"*) echo "✗ $1 ('$3' found in '${2:0:300}')"; fail=$((fail+1));; *) echo "✓ $1"; pass=$((pass+1));; esac; }
tk() { ddev artisan tinker --execute "$1" 2>/dev/null | grep -v -e WARNING -e DEPRECATED -e '^$' | tail -1; }
php() { tk "require_once 'storage/switchboard-helpers.php'; $1"; }
runq() { ddev artisan queue:work --queue=switchboard --stop-when-empty --sleep=0 >/dev/null 2>&1; }
sink() { docker exec "$W" bash -c "cat /tmp/switchboard-sink.jsonl 2>/dev/null"; }
# The last request the sink got on a path, as one JSON line.
sinkpath() { sink | python3 -I -c 'import json,sys
rows=[l for l in sys.stdin if l.strip() and json.loads(l)["path"]==sys.argv[1]]
print(rows[-1].strip() if rows else "")' "$1"; }
sinkclear() { docker exec "$W" bash -c ": > /tmp/switchboard-sink.jsonl"; }
pyj() { python3 -I -c 'import json,sys
lines=[json.loads(l) for l in sys.stdin if l.strip()]
print(eval(sys.argv[1]))' "$1"; }

# ---- Setup ----
cp "$SRC/retry.php" storage/switchboard-retry.php
cp "$SRC/fixtures.php" storage/switchboard-fixtures.php
cp "$SRC/cleanup.php" storage/switchboard-cleanup.php
cp "$SRC/helpers.php" storage/switchboard-helpers.php
cp "$SRC/gql-fixtures.php" storage/switchboard-gql-fixtures.php
cp "$SRC/multisite-fixtures.php" storage/switchboard-multisite-fixtures.php
cp "$SRC/multisite-remove.php" storage/switchboard-multisite-remove.php
mkdir -p resources/views/sb resources/views/_switchboard && cp "$SRC"/templates/*.* resources/views/sb/ && cp "$SRC"/templates/_switchboard/* resources/views/_switchboard/
if ! cmp -s "$SRC/config.php" config/craft/switchboard.php; then bin/harness-lock cp "$SRC/config.php" config/craft/switchboard.php; sleep 2; fi
# `docker exec -d`: a background child of a plain exec dies when the exec returns.
docker exec "$W" pgrep -f "php -S 127.0.0.1:1809[3]" >/dev/null || docker exec -d -w /var/www/sites/craft-switchboard/tests/Harness "$W" php -S 127.0.0.1:18093 sink.php
for i in 1 2 3 4 5 6 7 8 9 10; do docker exec "$W" curl -s -o /dev/null http://127.0.0.1:18093/ready && break; sleep 0.5; done
# Start clean: leftovers from an interrupted run would change what fires where.
tk "require 'storage/switchboard-cleanup.php';" >/dev/null
# Our own second site exists for the whole run: Craft only keeps `editSite:*` permissions saved while the
# install is multi-site, and other agents add and remove their sites concurrently.
MS=$(bin/harness-lock ddev artisan tinker --execute "require 'storage/switchboard-multisite-fixtures.php';" 2>/dev/null | grep -v -e WARNING -e DEPRECATED | tail -1)
FX=$(tk "require 'storage/switchboard-fixtures.php';")
fx() { echo "$FX" | python3 -I -c 'import json,sys; print(json.load(sys.stdin)[sys.argv[1]])' "$1"; }
EDITOR=$(fx editor); EDITOR2=$(fx editor2); OUTSIDER=$(fx outsider); BOSS=$(fx boss); FROZEN=$(fx frozen); MANAGER=$(fx manager)
ok "fixtures created" "$( [ -n "$BOSS" ] && echo yes)" "yes"
php "reset_switchboard();" >/dev/null; sinkclear; runq

# ---- Publishing, permissions, languages ----
php "rule('pub', 'entry.published', ['recipients' => ['users' => [$OUTSIDER, $BOSS, $FROZEN], 'groups' => [group_uid()]], 'channels' => ['database', 'mail'], 'routes' => [['channel' => 'webhook', 'to' => 'http://127.0.0.1:18093/hook']], 'payloadFields' => ['switchboardSummary', 'switchboardSecret'], 'body' => 'Secret: {{ element.fields.switchboardSecret ?? \"hidden\" }}. Summary: {{ element.fields.switchboardSummary ?? \"none\" }}.']);" >/dev/null
A=$(php "echo entry('switchboardNews', 'Alpha Story', ['switchboardSummary' => 'Alpha summary', 'switchboardSecret' => 'TOPSECRET-A'])->id;")
ok "nothing is sent during the save (queued after commit)" "$(php "echo count_deliveries();")" "0"
runq
st() { php "echo statuses($1);"; }
ok "admin: bell + mail sent" "$(st "['userId' => $BOSS, 'elementId' => $A]")" "database:sent,mail:sent"
ok "group member (fr): bell + mail sent" "$(st "['userId' => $EDITOR, 'elementId' => $A]")" "database:sent,mail:sent"
ok "group member (en): bell + mail sent" "$(st "['userId' => $EDITOR2, 'elementId' => $A]")" "database:sent,mail:sent"
ok "listed user who can't view the section: skipped" "$(php "echo skipped($OUTSIDER, $A);")" "can’t view this element"
ok "suspended user: skipped" "$(php "echo skipped($FROZEN, $A);")" "not an active user"
ok "webhook delivered" "$(st "['recipient' => '127.0.0.1', 'elementId' => $A]")" "webhook:sent"
ok "French recipient gets the French subject" "$(php "echo bell_titles($EDITOR);")" "Publié : Alpha Story"
ok "English recipient gets the English subject" "$(php "echo bell_titles($EDITOR2);")" "Published: Alpha Story"
ok "admin sees the admin-only field" "$(php "echo bell_messages($BOSS);")" "Secret: TOPSECRET-A. Summary: Alpha summary."
ok "editor doesn't see the admin-only field" "$(php "echo bell_messages($EDITOR);")" "Secret: hidden. Summary: Alpha summary."
HOOK=$(sinkpath /hook)
ok "webhook payload: summary included, admin-only field left out" "$(echo "$HOOK" | pyj 'json.dumps(json.loads(lines[0]["body"])["element"]["fields"])')" '{"switchboardSummary": "Alpha summary"}'
hasnt "webhook payload never contains the secret value" "$HOOK" "TOPSECRET"
ok "webhook payload: anonymous gets no CP edit URL" "$(echo "$HOOK" | pyj 'json.loads(lines[0]["body"])["element"]["cpEditUrl"]')" "None"
SECRET=$(php "echo rule_secret('pub');")
ok "webhook signature verifies with the rule's secret" "$(echo "$HOOK" | python3 -I -c 'import json,sys,hmac,hashlib
r=json.loads(sys.stdin.read()); h=r["headers"]["switchboard-signature"]; t=h.split(",")[0][2:]; v=h.split("v1=")[1]
print(hmac.compare_digest(hmac.new(sys.argv[1].encode(), (t+"."+r["body"]).encode(), hashlib.sha256).hexdigest(), v))' "$SECRET")" "True"
ok "webhook headers: event + delivery id" "$(echo "$HOOK" | pyj 'lines[0]["headers"]["switchboard-event"] + "/" + str(lines[0]["headers"]["switchboard-delivery"].isdigit())')" "entry.published/True"
ok "mail reached Mailpit for the editor, in French" "$(docker exec "$W" curl -s 'http://127.0.0.1:8025/api/v1/search?query=to:switchboard_editor@switchboard.test' | python3 -I -c 'import json,sys; m=json.load(sys.stdin)["messages"]; print(m[0]["Subject"] if m else "")')" "Publié : Alpha Story"

# Private section: editors can't view it, the admin can.
P=$(php "echo entry('switchboardPrivate', 'Private Plan', ['switchboardSummary' => 'Hush'])->id;"); runq
ok "private section: admin notified" "$(st "['userId' => $BOSS, 'elementId' => $P]")" "database:sent,mail:sent"
ok "private section: editor skipped" "$(php "echo skipped($EDITOR, $P);")" "can’t view this element"

# Saving again doesn't publish again; drafts and disabled entries don't publish.
php "save_entry($A, ['switchboardSummary' => 'Alpha edited']);" >/dev/null; runq
ok "re-saving a live entry doesn't notify “published” again" "$(php "echo count_deliveries(['elementId' => $A, 'userId' => $BOSS]);")" "2"
php "draft_of($A);" >/dev/null; runq
ok "saving a draft doesn't notify" "$(php "echo count_deliveries(['elementId' => $A, 'userId' => $BOSS]);")" "2"
D=$(php "echo entry('switchboardNews', 'Disabled Draft', [], false)->id;"); runq
ok "a disabled entry doesn't notify" "$(php "echo count_deliveries(['elementId' => $D]);")" "0"
php "save_entry($D, [], true);" >/dev/null; runq
ok "enabling it later notifies (published now)" "$(st "['userId' => $BOSS, 'elementId' => $D]")" "database:sent,mail:sent"

# A save that rolls back notifies nobody.
php "Illuminate\Support\Facades\DB::beginTransaction(); entry('switchboardNews', 'Rolled Back'); Illuminate\Support\Facades\DB::rollBack(); echo 1;" >/dev/null; runq
ok "a rolled-back save queues nothing" "$(php "echo count_deliveries(['subject' => 'Published: Rolled Back']);")" "0"

# Scheduled: a future post date isn't live; the scan fires once when it passes.
F=$(php "echo entry('switchboardNews', 'Future Post', [], true, '+1 day')->id;"); runq
ok "future post date: not notified yet" "$(php "echo count_deliveries(['elementId' => $F]);")" "0"
php "Illuminate\Support\Facades\DB::table('entries')->where('id', $F)->update(['postDate' => now()->subMinutes(2)->utc()->format('Y-m-d H:i:s')]); echo 1;" >/dev/null
ddev artisan craft:switchboard:scan --since="-10 minutes" >/dev/null 2>&1; runq
ok "scan notifies when the post date passes" "$(st "['userId' => $BOSS, 'elementId' => $F]")" "database:sent,mail:sent"
ddev artisan craft:switchboard:scan --since="-10 minutes" >/dev/null 2>&1; runq
ok "a second scan doesn't notify again" "$(php "echo count_deliveries(['elementId' => $F, 'userId' => $BOSS]);")" "2"
ok "scan skips entries already notified on save" "$(php "echo count_deliveries(['elementId' => $A, 'userId' => $BOSS]);")" "2"
php "disable_rule('pub');" >/dev/null

# Updated, with a condition, one channel.
php "rule('upd', 'entry.updated', ['recipients' => ['admins' => true, 'users' => [$EDITOR2]], 'channels' => ['database'], 'conditions' => [['attribute' => 'switchboardSummary', 'operator' => 'contains', 'value' => 'URGENT']], 'subject' => 'Changed: {{ element.title }} by rule {{ rule.handle }}']);" >/dev/null
php "save_entry($A, ['switchboardSummary' => 'calm']);" >/dev/null; runq
ok "condition not met: no “updated” notification" "$(php "echo count_deliveries(['trigger' => 'entry.updated']);")" "0"
php "save_entry($A, ['switchboardSummary' => 'now urgent!']);" >/dev/null; runq
ok "condition met (case-insensitive): notified" "$(st "['userId' => $EDITOR2, 'trigger' => 'entry.updated']")" "database:sent"
ok "custom subject rendered (sandboxed Twig)" "$(php "echo bell($EDITOR2)[0]['title'];")" "Changed: Alpha Story by rule upd"
php "disable_rule('upd');" >/dev/null

# ---- Conditions can't read hidden fields (no oracle) ----
php "rule('cond', 'entry.published', ['recipients' => ['admins' => true], 'channels' => ['database'], 'sources' => [section_uid('switchboardNews')], 'conditions' => [['attribute' => 'switchboardSecret', 'operator' => 'equals', 'value' => 'TOPSECRET-B']]]);" >/dev/null
B=$(php "echo entry('switchboardNews', 'Bravo Story', ['switchboardSummary' => 'Bravo', 'switchboardSecret' => 'TOPSECRET-B'])->id;"); runq
ok "a condition on a field behind a user condition never matches, even when the value does" "$(php "echo count_deliveries(['elementId' => $B]);")" "0"
php "rule('cond', 'entry.published', ['recipients' => ['admins' => true], 'channels' => ['database'], 'sources' => [section_uid('switchboardNews')], 'conditions' => [['attribute' => 'switchboardSummary', 'operator' => 'equals', 'value' => 'charlie']]]);" >/dev/null
C=$(php "echo entry('switchboardNews', 'Charlie Story', ['switchboardSummary' => 'Charlie'])->id;"); runq
ok "a condition on a field everyone can see works" "$(st "['userId' => $BOSS, 'elementId' => $C]")" "database:sent"
php "disable_rule('cond');" >/dev/null
# Relation fields: a condition only sees related elements an anonymous viewer could (live, public).
php "rule('rel', 'custom:rel', ['recipients' => ['users' => [$BOSS]], 'channels' => ['database'], 'conditions' => [['attribute' => 'switchboardRelated', 'operator' => 'contains', 'value' => 'merger']]]);" >/dev/null
HIDDEN=$(php "echo entry('switchboardNews', 'Hidden Merger', [], false)->id;")
NOTE=$(php "echo entry('switchboardNews', 'Public Note', ['switchboardRelated' => [$HIDDEN]])->id;")
php "app(JustinHoltWeb\Switchboard\Switchboard::class)->fire('rel', CraftCms\Cms\Entry\Elements\Entry::find()->id($NOTE)->one()); echo 1;" >/dev/null; runq
ok "a condition can't see a disabled related entry's title" "$(php "echo count_deliveries(['trigger' => 'custom:rel']);")" "0"
php "save_entry($HIDDEN, [], true);" >/dev/null
php "app(JustinHoltWeb\Switchboard\Switchboard::class)->fire('rel', CraftCms\Cms\Entry\Elements\Entry::find()->id($NOTE)->one()); echo 1;" >/dev/null; runq
ok "…and matches once that entry is live" "$(st "['trigger' => 'custom:rel', 'userId' => $BOSS]")" "database:sent"
php "disable_rule('rel');" >/dev/null

# ---- Body templates must live in _switchboard/ (checked again at render time) ----
php "rule('badtpl', 'custom:bad-template', ['recipients' => ['admins' => true], 'channels' => ['database'], 'template' => 'sb/bell.twig']);" >/dev/null
php "app(JustinHoltWeb\Switchboard\Switchboard::class)->fire('bad-template', CraftCms\Cms\Entry\Elements\Entry::find()->id($C)->one()); echo 1;" >/dev/null; runq
has "a template outside _switchboard/ is refused when rendering" "$(php "echo errors(['ruleId' => JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'badtpl')->value('id')]);")" "_switchboard/"
ok "template paths: traversal and other folders rejected" "$(php "echo json_encode(array_map(fn (\$p) => JustinHoltWeb\Switchboard\Rules\Rule::isTemplatePath(\$p), ['_switchboard/a.twig', '_switchboard/x/b.blade.php', '_switchboard/../index', '_switchboard//a', 'other/a.twig', '/_switchboard/a', '_switchboard']));")" "[true,true,false,false,false,false,false]"
php "disable_rule('badtpl');" >/dev/null

# ---- Twig and Blade body templates ----
php "rule('tw', 'custom:tpl', ['recipients' => ['users' => [$EDITOR]], 'channels' => ['database'], 'template' => '_switchboard/body.twig', 'payloadFields' => ['switchboardSummary']]);" >/dev/null
php "rule('bl', 'custom:tpl', ['recipients' => ['users' => [$EDITOR]], 'channels' => ['database'], 'template' => '_switchboard/body.blade.php', 'payloadFields' => ['switchboardSummary']]);" >/dev/null
php "app(JustinHoltWeb\Switchboard\Switchboard::class)->fire('tpl', CraftCms\Cms\Entry\Elements\Entry::find()->id($C)->one()); echo 1;" >/dev/null; runq
TW=$(php "echo collect(bell($EDITOR))->first(fn (\$n) => str_starts_with(\$n['message'], 'TWIG'))['message'] ?? '';")
BL=$(php "echo collect(bell($EDITOR))->first(fn (\$n) => str_starts_with(\$n['message'], 'BLADE'))['message'] ?? '';")
ok "Twig body template: snapshot, element, recipient, French |t" "$TW" "TWIG BODY Charlie Story #$C for Editor Switchboard [Charlie] Publié : Charlie Story"
ok "Blade body template gives the same message" "${BL#BLADE}" "${TW#TWIG}"
php "disable_rule('tw'); disable_rule('bl');" >/dev/null
php "rule('sbx', 'custom:sandbox', ['recipients' => ['admins' => true], 'channels' => ['database'], 'body' => '{{ craft.app.config.general.securityKey ?? \"x\" }}']);" >/dev/null
php "app(JustinHoltWeb\Switchboard\Switchboard::class)->fire('sandbox', CraftCms\Cms\Entry\Elements\Entry::find()->id($C)->one()); echo 1;" >/dev/null; runq
has "rule bodies are sandboxed: craft.app is refused" "$(php "echo errors(['ruleId' => JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'sbx')->value('id')]);")" "failed:Template error"
php "disable_rule('sbx');" >/dev/null

# ---- Users ----
php "rule('reg', 'user.registered', ['recipients' => ['admins' => true, 'subject' => true], 'channels' => ['database'], 'routes' => [['channel' => 'webhook', 'to' => 'http://127.0.0.1:18093/user']], 'body' => 'Name {{ element.title }} / email {{ element.email ?? \"hidden\" }}']);" >/dev/null
NEWBIE=$(php "\$u = new CraftCms\Cms\User\Elements\User(['username' => 'switchboard_newbie', 'email' => 'switchboard_newbie@switchboard.test', 'fullName' => 'Newbie Switchboard', 'active' => true]); CraftCms\Cms\Support\Facades\Elements::saveElement(\$u); echo \$u->id;"); runq
ok "user registered: admin notified, with the email" "$(php "echo collect(bell($BOSS))->first(fn (\$n) => str_contains(\$n['message'], 'Newbie'))['message'] ?? '';")" "Name Newbie Switchboard / email switchboard_newbie@switchboard.test"
ok "user registered: the new user is told about themselves" "$(st "['userId' => $NEWBIE]")" "database:sent"
ok "user registered: an anonymous destination never gets the email" "$(sinkpath /user | pyj 'json.loads(lines[0]["body"])["element"]["email"]')" "None"
php "disable_rule('reg');" >/dev/null
php "rule('act', 'user.activated', ['recipients' => ['admins' => true], 'channels' => ['database']]);" >/dev/null
php "\$u = CraftCms\Cms\User\Elements\User::find()->id($NEWBIE)->status(null)->one(); CraftCms\Cms\Support\Facades\Users::deactivateUser(\$u); CraftCms\Cms\Support\Facades\Users::activateUser(CraftCms\Cms\User\Elements\User::find()->id($NEWBIE)->status(null)->one()); echo 1;" >/dev/null; runq
ok "user activated: admin notified" "$(php "echo bell($BOSS)[0]['title'];")" "Account activated: Newbie Switchboard"
php "disable_rule('act');" >/dev/null

# ---- Assets ----
php "rule('up', 'asset.uploaded', ['recipients' => ['users' => [$EDITOR2, $OUTSIDER]], 'channels' => ['database'], 'sources' => ['$(fx volume)']]);" >/dev/null
ASSET=$(php "upload('switchboard-upload-$$.png');"); runq
ok "asset uploaded: someone who can view the volume is notified" "$(st "['userId' => $EDITOR2, 'elementId' => $ASSET]")" "database:sent"
ok "asset uploaded: someone who can't is skipped" "$(php "echo skipped($OUTSIDER, $ASSET);")" "can’t view this element"
php "disable_rule('up');" >/dev/null

# ---- Failed jobs (admins only; anonymous destinations don't get the error message) ----
php "rule('qf', 'queue.failed', ['recipients' => ['users' => [$EDITOR, $BOSS]], 'channels' => ['database'], 'routes' => [['channel' => 'webhook', 'to' => 'http://127.0.0.1:18093/failed']], 'conditions' => [['attribute' => 'queue', 'operator' => 'equals', 'value' => 'switchboard']]]);" >/dev/null
php "failing_job();" >/dev/null; runq; runq
has "failed job: admin notified" "$(php "echo bell($BOSS)[0]['title'];")" "A queued job failed:"
ok "failed job: non-admin skipped" "$(php "echo JustinHoltWeb\Switchboard\Delivery\Delivery::where(['userId' => $EDITOR, 'trigger' => 'queue.failed'])->value('error');")" "failed-job notifications go to admins only"
FAILED=$(sinkpath /failed)
ok "failed job: webhook gets the job and error class" "$(echo "$FAILED" | pyj 'json.loads(lines[0]["body"])["data"]["exception"]')" "RuntimeException"
hasnt "failed job: webhook never gets the error message" "$FAILED" "secret-token-123"
QF_BEFORE=$(php "echo count_deliveries(['trigger' => 'queue.failed']);")

# ---- Custom triggers + other channel formats + delivery failures ----
php "rule('cus', 'custom:order-shipped', ['recipients' => ['users' => [$EDITOR2]], 'channels' => ['database'], 'subject' => 'Shipped {{ element.title }}', 'body' => 'Tracking {{ data.tracking }} <b>&</b> @everyone', 'routes' => [['channel' => 'slack', 'to' => 'http://127.0.0.1:18093/slack'], ['channel' => 'discord', 'to' => 'http://127.0.0.1:18093/discord'], ['channel' => 'teams', 'to' => 'http://127.0.0.1:18093/teams']]]);" >/dev/null
ok "fire() queues the custom rule" "$(php "echo app(JustinHoltWeb\Switchboard\Switchboard::class)->fire('order-shipped', CraftCms\Cms\Entry\Elements\Entry::find()->id($C)->one(), ['tracking' => 'ZX9', 'ignored' => ['an', 'array']]);")" "1"
runq
ok "custom trigger: data reaches the template" "$(php "echo bell($EDITOR2)[0]['message'];")" "Tracking ZX9 & @everyone"
ok "custom trigger names are validated" "$(php "try { app(JustinHoltWeb\Switchboard\Switchboard::class)->fire('Bad Name!'); echo 'no'; } catch (InvalidArgumentException) { echo 'refused'; }")" "refused"
ok "Slack: mrkdwn-escaped text" "$(sinkpath /slack | pyj 'json.loads(lines[0]["body"])["text"].split(chr(10))[1]')" "Tracking ZX9 &amp; @everyone"
ok "Discord: mentions disabled" "$(sinkpath /discord | pyj 'json.dumps(json.loads(lines[0]["body"])["allowed_mentions"])')" '{"parse": []}'
ok "Teams: an Adaptive Card" "$(sinkpath /teams | pyj 'json.loads(lines[0]["body"])["attachments"][0]["contentType"]')" "application/vnd.microsoft.card.adaptive"
hasnt "Slack/Discord/Teams aren't signed (only webhooks are)" "$(sinkpath /slack)" "switchboard-signature"

php "rule('bad', 'custom:bad-hooks', ['routes' => [['channel' => 'webhook', 'to' => 'http://127.0.0.1:18093/redirect'], ['channel' => 'webhook', 'to' => 'http://127.0.0.1:18093/fail'], ['channel' => 'webhook', 'to' => 'http://127.0.0.1:18094/other-port'], ['channel' => 'webhook', 'to' => '\$SWITCHBOARD_NOT_SET'], ['channel' => 'webhook', 'to' => 'https://localhost/x']]]);" >/dev/null
php "app(JustinHoltWeb\Switchboard\Switchboard::class)->fire('bad-hooks'); echo 1;" >/dev/null; runq
derr() { php "echo JustinHoltWeb\Switchboard\Delivery\Delivery::where(['recipient' => '$1', 'trigger' => 'custom:bad-hooks'])->get()->map(fn (\$d) => \$d->status.':'.\$d->attempts.':'.\$d->error)->implode(' | ');"; }
has "a failing receiver is retried" "$(derr 127.0.0.1)" "sending:1:"
php "Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'switchboard')->update(['available_at' => time() - 1]); echo 1;" >/dev/null; runq
php "Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'switchboard')->update(['available_at' => time() - 1]); echo 1;" >/dev/null; runq
has "…three times, then marked failed" "$(derr 127.0.0.1)" "failed:3:RuntimeException: 127.0.0.1 answered HTTP 500."
hasnt "delivery errors never contain the destination's path" "$(derr 127.0.0.1)" "/fail"
ok "redirects are never followed" "$(derr 127.0.0.1 | tr '|' '\n' | grep -c 'HTTP 302')/$(sinkpath /landed)" "1/"
has "an allow-listed host:port doesn't open other ports" "$(php "echo errors(['recipient' => '127.0.0.1', 'trigger' => 'custom:bad-hooks']);")" "only https URLs"
has "an unset \$SWITCHBOARD_ variable fails cleanly" "$(derr '$SWITCHBOARD_NOT_SET')" "environment variable is empty"
has "a host that resolves to loopback is refused when sending" "$(derr localhost)" "private or reserved"
ok "Switchboard's own failed deliveries don't set off failed-job rules" "$(php "echo count_deliveries(['trigger' => 'queue.failed']);")" "$QF_BEFORE"
php "disable_rule('bad'); disable_rule('cus'); disable_rule('qf');" >/dev/null

# ---- SSRF guard ----
for url in https://127.0.0.1/x https://10.0.0.5/x https://172.16.3.4/x https://192.168.1.1/x https://169.254.169.254/latest/meta-data https://100.64.1.1/x https://0.0.0.0/x 'https://[::1]/x' 'https://[::ffff:127.0.0.1]/x' 'https://[fd00::1]/x' 'https://[64:ff9b::a00:1]/x' https://224.0.0.1/x; do
  ok "SSRF: $url refused" "$(php "ssrf('$url');" | grep -c 'private and reserved')" "1"
done
ok "SSRF: plain http refused" "$(php "ssrf('http://example.com/x');")" "only https URLs on port 443 are allowed"
ok "SSRF: other ports refused" "$(php "ssrf('https://example.com:8443/x');")" "only https URLs on port 443 are allowed"
ok "SSRF: credentials in the URL refused" "$(php "ssrf('https://user:pw@example.com/x');")" "credentials in the URL aren’t allowed"
ok "SSRF: Slack URLs must be Slack's" "$(php "ssrf('https://example.com/services/x', ['hooks.slack.com']);")" "the host must be hooks.slack.com"
ok "SSRF: a public IP is fine" "$(php "ssrf('https://1.1.1.1/x');")" "ok"
ok "routes: only \$SWITCHBOARD_* env vars are read" "$(php "echo json_encode([JustinHoltWeb\Switchboard\Channels\SafeHttp::isEnvReference('\$SWITCHBOARD_SLACK'), JustinHoltWeb\Switchboard\Channels\SafeHttp::isEnvReference('\$DB_PASSWORD'), JustinHoltWeb\Switchboard\Channels\SafeHttp::isEnvReference('https://x/\${SWITCHBOARD_A}')]);")" "[true,false,false]"

# ---- Sessions (login is rate-limited per IP across all agents: back off on 429) ----
login() {
  local j; j=$(mktemp)
  for i in 1 2 3 4 5 6 7 8 9 10; do
    curl -sk -c "$j" -o /dev/null "$U/admin/login"
    local x; x=$(awk '$6=="XSRF-TOKEN"{print $7}' "$j" | python3 -I -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
    local code; code=$(curl -sk -b "$j" -c "$j" -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H "X-XSRF-TOKEN: $x" \
      -X POST "$U/actions/users/login" --data-urlencode "loginName=$1" --data-urlencode "password=$2")
    [ "$code" = 200 ] && { echo "$j"; return; }
    sleep $((i * 6))
  done
  echo "$j"
}
xsrf() { awk '$6=="XSRF-TOKEN"{print $7}' "$1" | python3 -I -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))'; }
get() { local j=$1; shift; curl -sk -b "$j" -c "$j" "$@"; }
post() { local j=$1; shift; curl -sk -b "$j" -c "$j" -X POST -H "X-XSRF-TOKEN: $(xsrf "$j")" "$@"; }

# ---- Front-end bell (Twig and Blade), JSON feed, mark as read ----
php "CraftCms\Cms\User\Elements\User::find()->id($EDITOR)->one()->notify(new CraftCms\Cms\Cp\Notifications\CpNotification('A core CP notification')); echo 1;" >/dev/null
JE=$(login switchboard_editor switchboard-pass-1)
items() { get "$JE" "$U/sb/$1" | grep -o '<ul id="items">.*</ul>'; }
unread() { get "$JE" "$U/sb/$1" | grep -o '<p id="unread">[0-9]*' | grep -o '[0-9]*$'; }
EXPECT=$(php "echo app(JustinHoltWeb\Switchboard\Bell\Bell::class)->unreadCountFor(CraftCms\Cms\User\Elements\User::find()->id($EDITOR)->one());")
ok "Twig bell: the signed-in user's unread count" "$(unread bell)" "$EXPECT"
ok "Blade bell: the same count" "$(unread bell-blade)" "$EXPECT"
ok "Twig and Blade list the same notifications" "$(items bell | md5)" "$(items bell-blade | md5)"
hasnt "bell lists Switchboard's notifications only" "$(items bell)" "A core CP notification"
has "bell shows the French message" "$(items bell)" "Publié : Alpha Story"
ok "mark-read and feed URLs" "$(get "$JE" "$U/sb/bell" | grep -o '<p id="mark">[^<]*' | sed 's/.*>//')|$(get "$JE" "$U/sb/bell-blade" | grep -o '<p id="feed">[^<]*' | sed 's/.*>//')" "$U/actions/switchboard/notifications/mark-read|$U/actions/switchboard/notifications"
FEED=$(get "$JE" -H 'Accept: application/json' "$U/actions/switchboard/notifications?limit=2")
ok "JSON feed: unread count and a limited list" "$(echo "$FEED" | pyj 'str(lines[0]["unread"]) + "/" + str(len(lines[0]["notifications"]))')" "$EXPECT/2"
ok "JSON feed: signed out → 401" "$(curl -sk -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$U/actions/switchboard/notifications")" "401"
FIRST=$(echo "$FEED" | pyj 'lines[0]["notifications"][0]["id"]')
BOSSN=$(php "echo bell($BOSS)[0]['id'];")
ok "mark-read without a CSRF token → 419" "$(get "$JE" -X POST -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -d "ids[]=$FIRST" "$U/actions/switchboard/notifications/mark-read")" "419"
ok "mark-read: one of mine" "$(post "$JE" -H 'Accept: application/json' -d "ids[]=$FIRST" "$U/actions/switchboard/notifications/mark-read" | pyj 'lines[0]["marked"]')" "1"
ok "mark-read: someone else's ID does nothing" "$(post "$JE" -H 'Accept: application/json' -d "ids[]=$BOSSN" "$U/actions/switchboard/notifications/mark-read" | pyj 'lines[0]["marked"]')/$(php "echo (int) bell($BOSS)[0]['read'];")" "0/0"
ok "mark-read: rejects IDs that aren't UUIDs" "$(post "$JE" -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -d 'ids[]=1%20OR%201=1' "$U/actions/switchboard/notifications/mark-read")" "422"
ok "mark-read: all" "$(post "$JE" -H 'Accept: application/json' -d 'all=1' "$U/actions/switchboard/notifications/mark-read" | pyj 'lines[0]["unread"]')/$(unread bell)" "0/0"
ok "Craft's CP bell shows Switchboard notifications too" "$(php "CraftCms\\Cms\\craftAuth()->setUser(CraftCms\Cms\User\Elements\User::find()->id($BOSS)->one()); echo collect(app(CraftCms\Cms\Cp\Notifications\NotificationCenter::class)->get())->contains(fn (\$n) => \$n->title === 'Published: Alpha Story') ? 'yes' : 'no';")" "yes"

# ---- Control panel ----
JA=$(login switchboard_boss switchboard-pass-1)
for p in switchboard switchboard/rules/new switchboard/deliveries "switchboard/rules/$(php "echo JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'pub')->value('id');")"; do
  ok "CP $p loads" "$(get "$JA" -o /dev/null -w '%{http_code}' "$U/admin/$p")" "200"
done
LOG=$(get "$JA" "$U/admin/switchboard/deliveries")
has "deliveries page shows masked destinations" "$LOG" "127.0.0.1"
hasnt "deliveries page never shows a destination's path" "$LOG" "18093/hook"
save() { post "$1" -H 'Accept: application/json' -o /tmp/sb-save.json -w '%{http_code}' "$U/admin/switchboard/rules/save" "${@:2}"; }
ok "CP save without CSRF → 419" "$(get "$JA" -X POST -o /dev/null -w '%{http_code}' -d name=x "$U/admin/switchboard/rules/save")" "419"
ok "admin creates a rule with a webhook" "$(save "$JA" -d name=CP -d handle=cpRule -d enabled=1 -d trigger=entry.published -d 'channels[]=database' -d 'routes[0][channel]=webhook' -d 'routes[0][to]=http://127.0.0.1:18093/cp')" "200"
ok "…and it's stored, destinations encrypted" "$(php "echo JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'cpRule')->first()->routeList()[0]['to'] . '|' . (str_contains((string) Illuminate\Support\Facades\DB::table('switchboard_rules')->where('handle', 'cpRule')->value('routes'), '127.0.0.1') ? 'plain' : 'encrypted');")" "http://127.0.0.1:18093/cp|encrypted"
CP=$(php "echo JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'cpRule')->value('id');")
ok "CP: a private-network webhook is refused" "$(save "$JA" -d name=X -d handle=cpBad -d trigger=entry.published -d 'routes[0][channel]=webhook' -d 'routes[0][to]=https://10.1.2.3/x'; echo; python3 -c 'import json;print("routes" in json.load(open("/tmp/sb-save.json"))["errors"])')" "400
True"
ok "CP: a template outside _switchboard/ is refused" "$(save "$JA" -d name=X -d handle=cpBad -d trigger=entry.published -d template=_switchboard/../index >/dev/null; python3 -c 'import json;print("template" in json.load(open("/tmp/sb-save.json"))["errors"])')" "True"
ok "CP: an unknown trigger is refused" "$(save "$JA" -d name=X -d handle=cpBad -d trigger=nope >/dev/null; python3 -c 'import json;print("trigger" in json.load(open("/tmp/sb-save.json"))["errors"])')" "True"
ok "CP: test send (admin)" "$(post "$JA" -H 'Accept: application/json' -d id=$CP -d elementId=$C "$U/admin/switchboard/rules/test" | pyj 'lines[0]["message"]')" "Test sent. See Deliveries for what went where."

JM=$(login switchboard_manager switchboard-pass-1)
ok "manager (not admin) opens the rules" "$(get "$JM" -o /dev/null -w '%{http_code}' "$U/admin/switchboard")" "200"
ok "manager can't change a rule with destinations" "$(save "$JM" -d id=$CP -d name=Hijack -d handle=cpRule -d trigger=entry.published)" "403"
ok "manager can't delete it either" "$(post "$JM" -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -d id=$CP "$U/admin/switchboard/rules/delete")" "403"
ok "manager can't add destinations" "$(save "$JM" -d name=M -d handle=mgr -d trigger=entry.published -d "sources[sections][]=$(php "echo section_uid('switchboardNews');")" -d 'routes[0][channel]=webhook' -d 'routes[0][to]=https://1.1.1.1/x' >/dev/null; python3 -c 'import json;print("routes" in json.load(open("/tmp/sb-save.json"))["errors"])')" "True"
ok "manager can't choose a template" "$(save "$JM" -d name=M -d handle=mgr -d trigger=entry.published -d "sources[sections][]=$(php "echo section_uid('switchboardNews');")" -d template=_switchboard/body.twig >/dev/null; python3 -c 'import json;print("template" in json.load(open("/tmp/sb-save.json"))["errors"])')" "True"
ok "manager must name sources" "$(save "$JM" -d name=M -d handle=mgr -d trigger=entry.published >/dev/null; python3 -c 'import json;print("sources" in json.load(open("/tmp/sb-save.json"))["errors"])')" "True"
ok "manager can't pick a section they can't view" "$(save "$JM" -d name=M -d handle=mgr -d trigger=entry.published -d "sources[sections][]=$(php "echo section_uid('switchboardPrivate');")" >/dev/null; python3 -c 'import json;print("sources" in json.load(open("/tmp/sb-save.json"))["errors"])')" "True"
ok "manager can't use failed-job triggers" "$(save "$JM" -d name=M -d handle=mgr -d trigger=queue.failed >/dev/null; python3 -c 'import json;print("trigger" in json.load(open("/tmp/sb-save.json"))["errors"])')" "True"
ok "manager without “View users” can't probe accounts: existing and missing users fail alike" "$(save "$JM" -d name=M -d handle=mgr -d trigger=entry.published -d "sources[sections][]=$(php "echo section_uid('switchboardNews');")" -d users=switchboard_boss >/dev/null; python3 -c 'import json;print(json.load(open("/tmp/sb-save.json"))["errors"]["users"][0])')|$(save "$JM" -d name=M -d handle=mgr -d trigger=entry.published -d "sources[sections][]=$(php "echo section_uid('switchboardNews');")" -d users=nobody_at_all >/dev/null; python3 -c 'import json;print(json.load(open("/tmp/sb-save.json"))["errors"]["users"][0])')" "Without the “View users” permission you can only add yourself.|Without the “View users” permission you can only add yourself."
ok "manager saves a rule on a section they can view" "$(save "$JM" -d name=M -d handle=mgr -d enabled=1 -d trigger=entry.published -d "sources[sections][]=$(php "echo section_uid('switchboardNews');")" -d 'channels[]=database' -d "users=switchboard_manager")" "200"
MGR=$(php "echo JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'mgr')->value('id');")
ok "test send says the same whether or not the element matches" "$(post "$JM" -H 'Accept: application/json' -d id=$MGR -d elementId=$A "$U/admin/switchboard/rules/test" | pyj 'lines[0]["message"]')" "$(post "$JM" -H 'Accept: application/json' -d id=$MGR -d elementId=$C "$U/admin/switchboard/rules/test" | pyj 'lines[0]["message"]')"
PUB=$(php "echo JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'pub')->value('id');")
# Subjects are rendered per recipient: a non-admin reads only their own (and anonymous ones).
php "rule('subj', 'entry.published', ['sources' => [section_uid('switchboardNews')], 'recipients' => ['users' => [$BOSS, $MANAGER]], 'channels' => ['database'], 'payloadFields' => ['switchboardSecret'], 'subject' => 'S {{ element.fields.switchboardSecret ?? \"hidden\" }}']);" >/dev/null
php "echo entry('switchboardNews', 'Subject Leak', ['switchboardSecret' => 'LEAKY-SUBJ'])->id;" >/dev/null; runq
SUBJ=$(php "echo JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'subj')->value('id');")
SLOG=$(get "$JM" "$U/admin/switchboard/deliveries?rule=$SUBJ")
hasnt "manager's log never shows a subject rendered for someone else" "$SLOG" "LEAKY-SUBJ"
has "…shows their own" "$SLOG" "S hidden"
has "…and marks the other as written for another person" "$SLOG" "Written for another person"
has "admin's log shows both" "$(get "$JA" "$U/admin/switchboard/deliveries?rule=$SUBJ")" "LEAKY-SUBJ"
php "disable_rule('subj');" >/dev/null
MLOG=$(get "$JM" "$U/admin/switchboard/deliveries?rule=$PUB")
hasnt "manager's delivery log hides deliveries about content they can't view" "$MLOG" "Private Plan"
has "…but shows the ones they can" "$MLOG" "Alpha Story"
has "admin's log shows everything" "$(get "$JA" "$U/admin/switchboard/deliveries?rule=$PUB")" "Private Plan"
ok "a user without Switchboard access gets 403" "$(get "$JE" -o /dev/null -w '%{http_code}' "$U/admin/switchboard")" "403"
ok "settings page loads" "$(get "$JA" -o /dev/null -w '%{http_code}' "$U/admin/settings/plugins/switchboard")" "200"
php "disable_rule('cpRule'); disable_rule('mgr');" >/dev/null

# ---- GraphQL: your own notifications, for user-owned tokens only ----
if ! tk "echo app(CraftCms\Cms\Auth\PersonalAccessTokens\PersonalAccessTokens::class)->hasKeys() ? 'yes' : 'no';" | grep -q yes; then
  bin/harness-lock ddev artisan passport:keys --no-interaction >/dev/null 2>&1
fi
GQ=$(tk "require 'storage/switchboard-gql-fixtures.php';")
JWT_E=$(echo "$GQ" | pyj 'lines[0]["editor"]'); JWT_E2=$(echo "$GQ" | pyj 'lines[0]["editor2"]')
gq() { for i in 1 2 3 4 5 6; do
    r=$(curl -sk -w '\n%{http_code}' -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data "$(python3 -I -c 'import json,sys; print(json.dumps({"query": sys.argv[1]}))' "$2")" "$U/actions/graphql/api")
    [ "$(echo "$r" | tail -1)" = 429 ] && { sleep $((i * 5)); continue; }
    echo "$r" | sed '$d' | python3 -I -c 'import json,sys
try: r=json.load(sys.stdin)
except Exception: print("not json"); sys.exit()
if r.get("errors"): print("ERR:" + "; ".join(e.get("message","") for e in r["errors"])); sys.exit()
d=r.get("data") or {}
print(eval(sys.argv[1]))' "$3"; return; done; }
TOKEN=$(printf 'sbbell%026d' 0)
ok "gql: a shared token never gets the query, even with the permission" "$(gq "$TOKEN" '{ switchboardUnreadCount }' 'd')" 'ERR:Cannot query field "switchboardUnreadCount" on type "Query".'
php "Illuminate\Support\Facades\DB::table('notifications')->where('notifiable_id', $EDITOR2)->limit(1)->update(['read_at' => null]); echo 1;" >/dev/null
ok "gql: a user's token reads their own unread count" "$(gq "$JWT_E2" '{ switchboardUnreadCount }' 'd["switchboardUnreadCount"]')" "$(php "echo app(JustinHoltWeb\Switchboard\Bell\Bell::class)->unreadCountFor(CraftCms\Cms\User\Elements\User::find()->id($EDITOR2)->one());")"
ok "gql: notifications are the token user's own (same as their bell)" "$(gq "$JWT_E" '{ switchboardNotifications(limit: 3) { id title read } }' '",".join(n["id"] for n in d["switchboardNotifications"])')" "$(php "echo implode(',', array_column(array_slice(bell($EDITOR), 0, 3), 'id'));")"
ok "gql: limit is capped at 100" "$(gq "$JWT_E" '{ switchboardNotifications(limit: 5000) { id } }' 'len(d["switchboardNotifications"]) <= 100')" "True"

# ---- Multi-site: per-site rules, site access, the site's language for anonymous destinations ----
if [ "${SKIP_MULTISITE:-}" != 1 ]; then
  # Again, now that the fixture section exists: adds it to the French site.
  MS=$(bin/harness-lock ddev artisan tinker --execute "require 'storage/switchboard-multisite-fixtures.php';" 2>/dev/null | grep -v -e WARNING -e DEPRECATED | tail -1)
  FRID=$(echo "$MS" | pyj 'lines[0]["id"]'); FRUID=$(echo "$MS" | pyj 'lines[0]["uid"]')
  ok "second site created" "$( [ -n "$FRID" ] && echo yes)" "yes"
  php "app(CraftCms\Cms\User\UserPermissions::class)->saveUserPermissions($EDITOR2, ['editSite:$FRUID']); echo 1;" >/dev/null
  php "rule('frpub', 'entry.published', ['siteIds' => [$FRID], 'recipients' => ['groups' => [group_uid()], 'admins' => true], 'channels' => ['database'], 'routes' => [['channel' => 'webhook', 'to' => 'http://127.0.0.1:18093/fr']]]);" >/dev/null
  MSE=$(php "echo entry('switchboardNews', 'Bonjour Monde', [], true, null, 'switchboardfr')->id;"); runq
  ok "a rule for the French site fires for its entry there" "$(st "['userId' => $BOSS, 'elementId' => $MSE, 'siteId' => $FRID]")" "database:sent"
  ok "…and not for the same entry on the primary site" "$(php "echo count_deliveries(['elementId' => $MSE, 'siteId' => CraftCms\Cms\Support\Facades\Sites::getPrimarySite()->id, 'ruleId' => JustinHoltWeb\Switchboard\Rules\Rule::where('handle', 'frpub')->value('id')]);")" "0"
  # Craft's own view check already refuses entries on sites the user can't access; Switchboard checks site access too.
  ok "an editor without access to the French site is skipped" "$(php "echo JustinHoltWeb\Switchboard\Delivery\Delivery::where(['userId' => $EDITOR, 'elementId' => $MSE])->value('status');")" "skipped"
  ok "an editor with access to it is notified" "$(st "['userId' => $EDITOR2, 'elementId' => $MSE]")" "database:sent"
  ok "anonymous destinations get the site's language" "$(sinkpath /fr | pyj 'json.loads(lines[0]["body"])["message"]')" "« Bonjour Monde » a été publié sur Switchboard Français."
  ok "a user's own language beats the site's (editor2 has none: site language)" "$(php "echo bell($EDITOR2)[0]['title'];")" "Publié : Bonjour Monde"
  ok "the French site's URL is absolute" "$(sinkpath /fr | pyj 'json.loads(lines[0]["body"])["url"]')" "$U/switchboardfr/switchboard-news/bonjour-monde"
  php "disable_rule('frpub');" >/dev/null
fi

# ---- Commands ----
has "rules command lists rules without destinations" "$(ddev artisan craft:switchboard:rules 2>/dev/null)" "cpRule"
hasnt "rules command never prints destinations" "$(ddev artisan craft:switchboard:rules 2>/dev/null)" "18093"
has "test command" "$(ddev artisan craft:switchboard:test pub --user=switchboard_boss --element=$A 2>/dev/null)" "Queued"
has "prune command" "$(ddev artisan craft:switchboard:prune --days=1 2>/dev/null)" "Pruned"

# ---- Cleanup ----
ok "cleanup" "$(tk "require 'storage/switchboard-cleanup.php';")" "cleaned"
runq; sinkclear
rm -f resources/views/sb/bell.twig resources/views/sb/bell-blade.blade.php; rmdir resources/views/sb 2>/dev/null
rm -rf resources/views/_switchboard

ok "second site removed" "$(bin/harness-lock ddev artisan tinker --execute "require 'storage/switchboard-multisite-remove.php';" 2>/dev/null | grep -v -e WARNING -e DEPRECATED | tail -1)" "removed"

# ---- Uninstall / reinstall ----
if [ "${SKIP_REINSTALL:-}" != 1 ]; then
  bin/harness-lock ddev artisan craft:plugin:uninstall switchboard --no-interaction >/dev/null 2>&1
  ok "uninstall drops Switchboard's tables" "$(tk "echo json_encode([Illuminate\Support\Facades\Schema::hasTable('switchboard_rules'), Illuminate\Support\Facades\Schema::hasTable('switchboard_deliveries'), Illuminate\Support\Facades\Schema::hasTable('switchboard_ledger')]);")" "[false,false,false]"
  bin/harness-lock ddev artisan craft:plugin:install switchboard --no-interaction >/dev/null 2>&1
  ok "reinstall recreates them" "$(tk "echo json_encode([Illuminate\Support\Facades\Schema::hasTable('switchboard_rules'), Illuminate\Support\Facades\Schema::hasTable('switchboard_deliveries')]);")" "[true,true]"
fi

docker exec "$W" bash -c 'pkill -f "php -S 127.0.0.1:1809[3]"' >/dev/null 2>&1
echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
