#!/usr/bin/env bash
# Ships a NextaCRM release in one command, stopping at the first problem:
#   checks → tests → build + sign (offline key) → tag → GitHub Release with the ZIP → update hub (link)
#
#   tools/ship.sh 2.0.3                 # stable release
#   tools/ship.sh 2.1.0-beta.1 --beta   # pre-release (GitHub "Pre-release" + hub beta channel)
#   tools/ship.sh 2.0.3 --dry-run       # everything up to the build, nothing published
#
# Needs: git, php, gh (logged in: `gh auth login`), and environment variables:
#   NEXTA_SIGNING_KEY  path to the offline Ed25519 secret (never commit it)
#   NEXTA_HUB_TOKEN    optional — publish token for updates.nextawebsolution.com (skip the hub step if unset)
#   NEXTA_HUB          optional — hub URL (default https://updates.nextawebsolution.com)
#   HUB_PUBLISH        optional — path to nexta-updates/tools/publish.php (default ../nexta-updates/tools/publish.php)
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

V="${1:-}"; shift || true
BETA=0; DRY=0; YES=0; NOTES_FILE=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --beta) BETA=1 ;; --dry-run) DRY=1 ;; --yes) YES=1 ;;
    --notes) NOTES_FILE="$2"; shift ;;
    *) echo "Unknown option $1"; exit 1 ;;
  esac; shift
done
step() { printf '\n\033[1;34m▸ %s\033[0m\n' "$*"; }
die() { printf '\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }
ok() { printf '  \033[32m✓\033[0m %s\n' "$*"; }

[[ "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || die "Usage: tools/ship.sh X.Y.Z [--beta] [--notes NOTES.md] [--dry-run]"
[[ "$V" == *-* && $BETA -eq 0 ]] && die "Pre-release versions need --beta."
TAG="v$V"; ZIP="dist/nextacrm-$V.zip"; HUB="${NEXTA_HUB:-https://updates.nextawebsolution.com}"
HUB_PUBLISH="${HUB_PUBLISH:-../nexta-updates/tools/publish.php}"

step "Checking the repository"
[[ -z "$(git status --porcelain)" ]] || die "Uncommitted changes — commit or stash them first (they would end up in the ZIP)."
ok "working tree clean"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[[ "$BRANCH" == "main" || "$BRANCH" == release/* ]] || die "Release from main or a release/* branch (you are on $BRANCH)."
git fetch -q origin "$BRANCH" --tags 2>/dev/null || true
if git rev-parse -q --verify "origin/$BRANCH" >/dev/null; then
  BEHIND=$(git rev-list --count "HEAD..origin/$BRANCH"); AHEAD=$(git rev-list --count "origin/$BRANCH..HEAD")
  [[ $BEHIND -eq 0 ]] || die "$BRANCH is $BEHIND commit(s) behind origin — pull first."
  if [[ $AHEAD -ne 0 ]]; then [[ $DRY -eq 1 ]] && echo "  ! $AHEAD commit(s) not pushed yet (fine for a dry run)" || die "Push $BRANCH first (git push origin $BRANCH) so the tag points at a published commit."; else ok "$BRANCH matches origin"; fi
fi
git rev-parse -q --verify "refs/tags/$TAG" >/dev/null && die "Tag $TAG already exists. Versions are never reused — bump the version."
MV=$(php -r 'echo json_decode(file_get_contents("manifest.json"), true)["version"];')
[[ "$MV" == "$V" ]] || die "manifest.json says $MV, not $V. Bump the version and commit first."
ok "manifest.json version $V"
grep -q "^## $V " CHANGELOG.md || die "CHANGELOG.md has no '## $V — date' section."
ok "CHANGELOG.md has $V"
command -v gh >/dev/null || die "Install the GitHub CLI (https://cli.github.com) and run: gh auth login"
[[ -n "${NEXTA_SIGNING_KEY:-}" && -f "$NEXTA_SIGNING_KEY" ]] || die "Set NEXTA_SIGNING_KEY to the path of the offline signing key."
case "$(cd "$(dirname "$NEXTA_SIGNING_KEY")" && pwd)/" in "$(pwd)/"*) die "The signing key must not be inside the repository.";; esac
ok "signing key found outside the repository"

step "Release notes"
mkdir -p dist
NOTES="dist/NOTES-$V.md"
if [[ -n "$NOTES_FILE" ]]; then cp "$NOTES_FILE" "$NOTES"; else
  awk -v v="$V" '$0 ~ "^## "v" " {f=1; next} f && /^## / {exit} f' CHANGELOG.md | sed -E 's/^\*\*(.+)\*\*$/### \1/' > "$NOTES"
fi
[[ -s "$NOTES" ]] || die "Release notes are empty."
ok "$(wc -l < "$NOTES") lines → $NOTES"

step "Tests"
find . -name '*.php' -not -path './vendor/*' -not -path './dist/*' -print0 | xargs -0 -n1 -P4 php -l 2>&1 | grep -v '^No syntax errors' && die "PHP syntax errors" || ok "PHP lint"
command -v node >/dev/null && { node --check assets/js/app.js && ok "JavaScript syntax"; } || echo "  ! node not installed — skipped JS check"
NEXTA_SIGNING_KEY="$NEXTA_SIGNING_KEY" php tests/unit.php | tail -1 | grep -q ' 0 failed' && ok "unit tests" || die "Unit tests failed (php tests/unit.php)"
php tests/load_all.php | tail -1 | grep -q ' 0 problems' && ok "all classes load" || die "Class loading failed (php tests/load_all.php)"

step "Build and sign"
rm -f "$ZIP" dist/latest.json
php tools/release.php --key-file="$NEXTA_SIGNING_KEY" --out=dist --notes="$(grep -m1 -E '^- ' "$NOTES" | sed 's/^- //' | cut -c1-240)" >/dev/null
[[ -f "$ZIP" ]] || die "Build did not produce $ZIP"
unzip -l "$ZIP" | grep -E 'config/config\.php|\.sqlite|\.secret|\.env$' && die "Sensitive file inside the package!"
SHA=$(php -r 'echo json_decode(file_get_contents("dist/latest.json"), true)["sha256"];')
[[ "$(php -r "echo hash_file('sha256', '$ZIP');")" == "$SHA" ]] || die "latest.json does not match the ZIP"
ok "$ZIP ($(du -h "$ZIP" | cut -f1)), sha256 ${SHA:0:16}…, signed"

if [[ $DRY -eq 1 ]]; then step "Dry run finished — nothing was tagged or published"; exit 0; fi

REPO=$(gh repo view --json nameWithOwner -q .nameWithOwner)
URL="https://github.com/$REPO/releases/download/$TAG/nextacrm-$V.zip"
echo; echo "About to publish NextaCRM $V$([[ $BETA -eq 1 ]] && echo ' (beta)') to $REPO and $([[ -n "${NEXTA_HUB_TOKEN:-}" ]] && echo "$HUB" || echo 'GitHub only (NEXTA_HUB_TOKEN not set)')."
if [[ $YES -ne 1 ]]; then read -r -p "Continue? [y/N] " a; [[ "$a" == y || "$a" == Y ]] || die "Cancelled."; fi

step "Tag"
git tag -a "$TAG" -m "NextaCRM $V"
git push origin "$TAG"
ok "pushed $TAG"

step "GitHub Release"
gh release create "$TAG" "$ZIP" dist/latest.json --title "NextaCRM $V" --notes-file "$NOTES" --verify-tag $([[ $BETA -eq 1 ]] && echo --prerelease)
ok "$URL"

if [[ -n "${NEXTA_HUB_TOKEN:-}" ]]; then
  step "Update hub"
  [[ -f "$HUB_PUBLISH" ]] || die "Hub publisher not found at $HUB_PUBLISH (clone NextaWebSolution/nexta-updates next to this repo or set HUB_PUBLISH)."
  php "$HUB_PUBLISH" release --hub="$HUB" --product=nextacrm --meta=dist/latest.json --notes-file="$NOTES" --url="$URL" $([[ $BETA -eq 1 ]] && echo --channel=beta) | head -1
  ok "$HUB/nextacrm/latest.json"
fi
step "Done — NextaCRM $V is out"
