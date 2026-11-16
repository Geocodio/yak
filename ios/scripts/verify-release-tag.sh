#!/usr/bin/env bash
# Refuses a release tag the release workflow must not build with its secrets.
#   verify-release-tag.sh [<tag> ...]   with no tag, checks every ios/v* tag
# A tag passes when it is annotated, named ios/v<version>-<build>, and points at a commit that is an
# ancestor of TRUSTED_REF (default origin/main). Exits non-zero with one line per refused tag.
set -u
trusted="${TRUSTED_REF:-origin/main}"
cd "$(dirname "$0")" || exit 2

if ! git rev-parse --verify --quiet "$trusted^{commit}" >/dev/null; then
    echo "release tag check: the trusted ref $trusted does not exist here; fetch it first" >&2
    exit 1
fi

tags=("$@")
if [ ${#tags[@]} -eq 0 ]; then
    while IFS= read -r name; do tags+=("$name"); done < <(git for-each-ref 'refs/tags/ios/v*' --format='%(refname:short)')
fi

refused=0
for tag in "${tags[@]}"; do
    if ! [[ "$tag" =~ ^ios/v[0-9]+\.[0-9]+\.[0-9]+-[0-9]+(\.[0-9]+)*$ ]]; then
        echo "release tag check: $tag is not named ios/v<version>-<build>"
        refused=1
    elif [ "$(git cat-file -t "refs/tags/$tag" 2>/dev/null)" != "tag" ]; then
        echo "release tag check: $tag is missing or not an annotated tag"
        refused=1
    elif ! git merge-base --is-ancestor "refs/tags/$tag^{commit}" "$trusted"; then
        echo "release tag check: $tag points at a commit that is not on $trusted"
        refused=1
    fi
done
exit "$refused"
