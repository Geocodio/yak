#!/bin/bash
# Welcome splash for thread sandboxes, shown at the start of an interactive login shell.
# Per-thread values come from ~/.yak-thread.env as plain KEY=value lines. The file is parsed
# line by line, never sourced, and every value is stripped of control characters before use.

ENV_FILE="${YAK_THREAD_ENV:-$HOME/.yak-thread.env}"

TITLE='' PUBLIC_ID='' REPO='' BRANCH='' DOCS_URL=''
if [ -r "$ENV_FILE" ]; then
  while IFS='=' read -r key value; do
    value=$(printf '%s' "$value" | tr -d '[:cntrl:]')
    case "$key" in
      TITLE) TITLE=$value ;;
      PUBLIC_ID) PUBLIC_ID=$value ;;
      REPO) REPO=$value ;;
      BRANCH) BRANCH=$value ;;
      DOCS_URL) DOCS_URL=$value ;;
    esac
  done < "$ENV_FILE"
fi

truncate() {
  if [ "${#1}" -gt "$2" ]; then printf '%s...' "${1:0:$(($2 - 3))}"; else printf '%s' "$1"; fi
}

ACCENT='' DIM='' RESET=''
if [ -z "${NO_COLOR:-}" ] && [ "${TERM:-dumb}" != dumb ] && [ "$(tput colors 2>/dev/null || echo 0)" -ge 8 ]; then
  case "${COLORTERM:-}" in
    truecolor | 24bit) ACCENT=$'\e[38;2;196;100;58m' ;;
    *) ACCENT=$'\e[38;5;166m' ;;
  esac
  DIM=$'\e[2m'
  RESET=$'\e[0m'
fi

printf '%s' "$ACCENT"
cat <<'ART'
  __   __     _
  \ \ / /_ _ | | __
   \ V / _` || |/ /
    | | (_| ||   <
    |_|\__,_||_|\_\
ART
printf '%s' "$RESET"

label() { printf '  %s%-8s%s %s\n' "$DIM" "$1" "$RESET" "$2"; }

echo
label thread "$(truncate "${TITLE:-untitled}" 44) ${DIM}(${PUBLIC_ID:-?})${RESET}"
label repo "$(truncate "${REPO:--}" 30) ${DIM}on${RESET} $(truncate "${BRANCH:--}" 30)"
label code /workspace
label changes 'git status, git diff'
label server 'docker compose ps, docker compose logs -f'
label restart 'docker compose restart, or cold_start in .yak/preview.yml'
echo
printf '  %sClaude sees your edits at its next step. Closing the tab ends this shell.%s\n' "$DIM" "$RESET"
[ -n "$DOCS_URL" ] && printf '  %sDocs%s %s\n' "$ACCENT" "$RESET" "$DOCS_URL"
echo
exit 0
