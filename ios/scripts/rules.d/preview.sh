# Preview rules. Sourced by check-rules.sh.

# Only "Open PR" is rust-colored; the preview's controls are neutral. The activity strip is shared by every pane.
no_rust_folders+=(Yak/Preview)
rust_allowed_files+=()

# A preview page lives in a data store of its own per instance (PreviewWebData), never the default or a
# non-persistent one, so its cookie leaves with the instance.
preview_store_hits=$(grep -rnE --include='*.swift' 'WKWebsiteDataStore\.(default|nonPersistent)\(' Yak/Preview 2>/dev/null || true)
if [ -n "$preview_store_hits" ]; then
    echo "$preview_store_hits"
    fail 'WKWebsiteDataStore.default() or .nonPersistent() under Yak/Preview'
fi
