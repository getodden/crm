#!/usr/bin/env bash
#
# Tags a release and publishes it to the split repositories, without GitHub Actions: tag the monorepo, then split each package out
# of the tagged commit and push the split's main branch and tag to getodden/crm-<package> (which Packagist reads).
#
#   scripts/release.sh v0.10.9             tag the tip of main, push the tag, split and push each package
#   scripts/release.sh v0.10.9 --dry-run   check everything, including that each split repository would accept the push; change nothing
#
# Refuses unless: you are on main with a clean tree that equals origin/main, the version is new and looks like vX.Y.Z, and HEAD has
# a successful "local-ci" status (scripts/ci.sh --report). The split is deterministic, so the workflow in .github/workflows/split.yml
# pushing the same tag afterwards finds nothing to do. Needs write access to the split repositories over SSH.

set -euo pipefail

cd "$(dirname "$0")/.."

PACKAGES=(core sales filament)

version="${1:-}"
dry=0
[ "${2:-}" = "--dry-run" ] && dry=1

case "$version" in
    v[0-9]*.[0-9]*.[0-9]*) ;;
    *) echo "Usage: scripts/release.sh vX.Y.Z [--dry-run]" >&2; exit 2 ;;
esac

[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || { echo "Not on main." >&2; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "The working tree has uncommitted changes." >&2; exit 1; }
git fetch -q origin main --tags
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || { echo "main is not at origin/main." >&2; exit 1; }
git rev-parse -q --verify "refs/tags/$version" >/dev/null && { echo "$version already exists." >&2; exit 1; }

sha="$(git rev-parse HEAD)"
repo="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
status="$(gh api "repos/$repo/commits/$sha/statuses" --jq '[.[] | select(.context=="local-ci")][0].state')"
[ "$status" = "success" ] || { echo "HEAD has no successful local-ci status (run scripts/ci.sh --report). Got: ${status:-none}" >&2; exit 1; }

# Split every package first, so a failure to split stops the release before anything is tagged or pushed. (Plain arrays: macOS ships
# bash 3.2, which has no associative ones. SPLITS[i] is the split of PACKAGES[i].)
SPLITS=()
for package in "${PACKAGES[@]}"; do
    split="$(git subtree split -q --prefix="packages/$package" HEAD)"
    [ -n "$split" ] || { echo "Could not split packages/$package." >&2; exit 1; }
    SPLITS+=("$split")
    echo "packages/$package -> ${split:0:7}"
done

push() {
    local index="$1" ref="$2" flag="${3:-}"
    git push $flag "git@github.com:getodden/crm-${PACKAGES[$index]}.git" "${SPLITS[$index]}:$ref"
}

if [ "$dry" -eq 1 ]; then
    for index in "${!PACKAGES[@]}"; do
        push "$index" "refs/heads/main" --dry-run
        push "$index" "refs/tags/$version" --dry-run
    done
    echo "Dry run: $version would be tagged at $sha; nothing was changed."
    exit 0
fi

git tag "$version" "$sha"
git push -q origin "$version"

for index in "${!PACKAGES[@]}"; do
    push "$index" "refs/heads/main"
    push "$index" "refs/tags/$version"
done

echo "Released $version at $sha."
