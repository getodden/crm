#!/usr/bin/env bash
#
# Publishes main to the split repositories without GitHub Actions: split each package out of main and push the split's main branch to
# getodden/crm-<package> (which Packagist reads). It does what .github/workflows/split.yml does on a push to main, from this machine.
# Run it after merging a pull request that changes packages/.
#
#   scripts/split.sh                 split origin/main and push each package that is behind
#   scripts/split.sh --dry-run       show what would be pushed, including that each repository would accept it; change nothing
#   scripts/split.sh --website       after the splits, redeploy odden.io (the docs are built from main) with $WEBSITE_DEPLOY_HOOK
#
# Releases do not need this: scripts/release.sh pushes each split's main and the version tag itself.
#
# Refuses unless you are on main with a clean tree that equals origin/main. A package whose split repository is already at the split is
# left alone; a push that is not a fast-forward is refused by git (nothing is forced). Needs write access to the split repositories over
# SSH. SPLIT_REMOTE_BASE (default git@github.com:getodden) names where the repositories are, for trying the script against local ones.

set -euo pipefail

cd "$(dirname "$0")/.."

PACKAGES=(core sales filament)
BASE="${SPLIT_REMOTE_BASE:-git@github.com:getodden}"

dry=0
website=0

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) dry=1; shift ;;
        --website) website=1; shift ;;
        -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

[ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || { echo "Not on main." >&2; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "The working tree has uncommitted changes." >&2; exit 1; }
git fetch -q origin main
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || { echo "main is not at origin/main." >&2; exit 1; }

# Split every package first, so a failure to split stops everything before anything is pushed.
SPLITS=()
for package in "${PACKAGES[@]}"; do
    split="$(git subtree split -q --prefix="packages/$package" HEAD)"
    [ -n "$split" ] || { echo "Could not split packages/$package." >&2; exit 1; }
    SPLITS+=("$split")
done

pushed=0
failed=()
for index in "${!PACKAGES[@]}"; do
    package="${PACKAGES[$index]}"
    split="${SPLITS[$index]}"
    remote="$BASE/crm-${package}.git"
    current="$(git ls-remote "$remote" refs/heads/main | cut -f1)"

    if [ "$current" = "$split" ]; then
        echo "crm-$package: up to date (${split:0:7})"
        continue
    fi

    # One repository refusing the push does not stop the others; the script reports it and fails at the end.
    if [ "$dry" -eq 1 ]; then
        if git push -q --dry-run "$remote" "$split:refs/heads/main"; then
            echo "crm-$package: would push ${split:0:7} (now ${current:0:7})"
        else
            echo "crm-$package: would be REFUSED (now ${current:0:7})" >&2; failed+=("$package")
        fi
    elif git push -q "$remote" "$split:refs/heads/main"; then
        echo "crm-$package: pushed ${split:0:7} (was ${current:0:7})"
        pushed=$((pushed + 1))
    else
        echo "crm-$package: REFUSED (now ${current:0:7})" >&2; failed+=("$package")
    fi
done

if [ "${#failed[@]}" -gt 0 ]; then
    echo "Failed: ${failed[*]}. Nothing is forced; look at what the split repository holds before trying again." >&2
    exit 1
fi

[ "$dry" -eq 1 ] && echo "Dry run: nothing was changed." || echo "Done: $pushed package(s) pushed."

if [ "$website" -eq 1 ]; then
    [ -n "${WEBSITE_DEPLOY_HOOK:-}" ] || { echo "WEBSITE_DEPLOY_HOOK is not set (see docs/CONTRIBUTING.md, Publishing)." >&2; exit 1; }
    if [ "$dry" -eq 1 ]; then echo "Dry run: would redeploy the website."; else
        curl --fail --silent --show-error "$WEBSITE_DEPLOY_HOOK" >/dev/null && echo "Asked the website to redeploy."
    fi
fi
