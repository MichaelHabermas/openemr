#!/usr/bin/env bash
# Push the current branch to GitHub and GitLab (Gauntlet).
#
# Default remotes match this repo:
#   github → GitHub (e.g. github.com/MichaelHabermas/openemr)
#   origin → GitLab (e.g. labs.gauntletai.com/.../openemr)
#
# Override if your remotes differ:
#   GITHUB_REMOTE=my-github GITLAB_REMOTE=my-gitlab ./push-github-and-gitlab.sh

set -euo pipefail

GITHUB_REMOTE="${GITHUB_REMOTE:-github}"
GITLAB_REMOTE="${GITLAB_REMOTE:-origin}"

BRANCH="$(git rev-parse --abbrev-ref HEAD)"

if [[ "${BRANCH}" == "HEAD" ]]; then
    echo "error: detached HEAD; checkout a branch first." >&2
    exit 1
fi

echo "Pushing ${BRANCH} → ${GITHUB_REMOTE}..."
git push "${GITHUB_REMOTE}" "${BRANCH}"

echo "Pushing ${BRANCH} → ${GITLAB_REMOTE}..."
git push "${GITLAB_REMOTE}" "${BRANCH}"

echo "Done. Both remotes updated."
