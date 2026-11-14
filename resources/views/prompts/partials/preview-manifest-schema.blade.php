`.yak/preview.yml` is a YAML mapping at the repository root. Unknown keys are rejected. The keys:

- `port` (integer 1 to 65535, REQUIRED): the port the app listens on.
- `health_probe_path` (string starting with `/`, REQUIRED): the path Yak requests to check the app is up. Not `health_path`.
- `cold_start` (string, optional, no default): the shell command that starts the dev server from a stopped sandbox. It runs through `bash -lc` from the home directory, so `cd /workspace` first. It must return; start long-running servers in the background (`nohup ... &`).
- `checkout_refresh` (string, optional, no default): the command that refreshes the app after a new commit (install, migrate, build).
- `wake_timeout_seconds` (integer >= 1, default {{ config('yak.deployments.default_wake_timeout_seconds') }})
- `cold_start_timeout_seconds` (integer >= 1, default {{ config('yak.deployments.default_cold_start_timeout_seconds') }})
- `checkout_refresh_timeout_seconds` (integer >= 1, default {{ config('yak.deployments.default_checkout_refresh_timeout_seconds') }})
- `health_probe_timeout_seconds` (integer >= 1, default {{ config('yak.deployments.default_health_probe_timeout_seconds') }})

Example:

```yaml
port: 3000
cold_start: cd /workspace && nohup npm run dev > /tmp/dev-server.log 2>&1 &
health_probe_path: /
```
