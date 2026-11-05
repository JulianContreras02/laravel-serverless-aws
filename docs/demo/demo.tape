# Terminal demo shown at the top of the README. Every command runs for real
# against the local Docker stack. Regenerate the GIF with: docs/demo/record.sh

Output docs/demo/demo.gif

Require curl
Require jq
Require docker
Require socat

# The prompt is a bare "$" (VHS trims the trailing space): Wait matches /^[$]$/.
Set Shell bash
Set FontSize 15
Set Width 1100
Set Height 680
Set Padding 16
Set Theme "Catppuccin Mocha"
Set WindowBar Colorful
Set TypingSpeed 15ms
Set Framerate 20
Set CursorBlink false

Hide
Type `export PS1='$ '` Enter
# Inside the recorder container, localhost:8000 forwards to the app's published port on the host.
Type "socat TCP-LISTEN:8000,fork,reuseaddr TCP:host.docker.internal:8000 > /dev/null 2>&1 &" Enter
Type "clear" Enter
Show

Type "# Start the stack: Bref's Lambda runtime image (PHP-FPM) and MySQL 8.4" Enter
# --wait returns once both containers report healthy.
Type "docker compose up -d --wait" Enter
Wait@90s /^[$]$/
Type "curl -s localhost:8000/health | jq -c" Enter
Sleep 1.5s

Type `export BASE=localhost:8000 AUTH='Authorization: Bearer local-dev-token'` Enter
Type "# Create a short link (the code is random base62)" Enter
Type `curl -s -X POST $BASE/api/links -H "$AUTH" -H 'Content-Type: application/json' -d '{"url": "https://example.org/launch"}' | tee /tmp/link.json | jq` Enter
Sleep 2.5s
Type "code=$(jq -r .data.code /tmp/link.json)" Enter

Type "# Two visitors follow it: 302 to the target, one click recorded each" Enter
Type `for ref in news.example.net social.example.com; do curl -s -o /dev/null -A iPhone -e https://$ref/post -w '%{http_code} -> %{redirect_url}\n' $BASE/$code; done` Enter
Sleep 2s

Type "# Aggregate now (EventBridge runs it every 5 min in AWS): JSON log + EMF metric" Enter
Type "docker compose exec app php artisan stats:rollup" Enter
Wait@30s /^[$]$/
Sleep 2.5s

Type "clear" Enter
Type `curl -s -H "$AUTH" "$BASE/api/links/$code/stats?days=2" | jq '.data | del(.period)'` Enter
Sleep 5s
