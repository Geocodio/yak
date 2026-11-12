{!! '#!/bin/sh' !!}{{-- PHP drops a literal shebang on the first line of a compiled view, so it is printed. --}}
{{-- Claude Code keeps each plugin version in its own cache directory
     (~/.claude/plugins/cache/<marketplace>/<plugin>/<version>/) and removes the old one after an
     update, and it only puts a plugin's bin/ on the PATH of its own Bash tool. So ~/.local/bin/yak
     is a small wrapper that looks up the newest installed plugin launcher each time it runs,
     instead of a symlink into a versioned directory. --}}
set -eu

yak_url={!! $quotedInstanceUrl !!}
marketplace={!! $quotedMarketplace !!}

say() {
  printf '==> %s\n' "$1"
}

if ! command -v claude >/dev/null 2>&1; then
  echo "Claude Code is not installed. Install it first: https://code.claude.com/docs/en/setup" >&2
  exit 1
fi

say "Adding the Yak marketplace"
claude plugin marketplace add "$marketplace"

say "Installing the Yak plugin"
claude plugin install yak@yak

say "Putting yak on your PATH at ~/.local/bin/yak"
mkdir -p "$HOME/.local/bin"
cat > "$HOME/.local/bin/yak" <<'WRAPPER'
#!/bin/sh
root=${CLAUDE_CODE_PLUGIN_CACHE_DIR:-$HOME/.claude/plugins}
for version_dir in $(ls -dt "$root"/cache/yak/yak/*/ 2>/dev/null); do
  [ -e "${version_dir}.orphaned_at" ] && continue
  [ -x "${version_dir}bin/yak" ] && exec "${version_dir}bin/yak" "$@"
done
echo "The Yak plugin is not installed. Run: claude plugin install yak@yak" >&2
exit 1
WRAPPER
chmod +x "$HOME/.local/bin/yak"

case ":$PATH:" in
  *":$HOME/.local/bin:"*) ;;
  *)
    # shellcheck disable=SC2016
    say 'Add ~/.local/bin to your PATH: export PATH="$HOME/.local/bin:$PATH"'
    PATH="$HOME/.local/bin:$PATH"
    ;;
esac

say "Signing in to $yak_url"
yak login --url "$yak_url"
