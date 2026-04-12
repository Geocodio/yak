# CI Docker Build Pipeline

## Problem

The Docker image is built on the server during Ansible provisioning. This is slow (full npm/composer install + Vite build on every deploy), couples build failures to deploys, and prevents rollbacks to known-good images.

## Design

### GitHub Actions workflow (`.github/workflows/docker.yml`)

Triggers on push to `main` after tests pass. Builds the Docker image using the existing multi-stage Dockerfile and pushes to GitHub Container Registry (`ghcr.io/geocodio/yak`).

Tags:
- `latest` — always points to the most recent main build
- Commit SHA (short) — every build is addressable for rollback

Uses `GITHUB_TOKEN` for registry auth (no extra secrets needed).

### Ansible changes

The `yak-container` role simplifies from "rsync + build + run" to "pull + run":

1. **Remove:** `synchronize` task (rsync of app to server)
2. **Remove:** `docker_image build` task
3. **Remove:** `yak_app_path` variable — no app directory on server
4. **Add:** `docker_login` to ghcr.io (using a GitHub PAT stored in vault as `ghcr_token`)
5. **Add:** `docker_image pull` of `ghcr.io/geocodio/yak:latest`
6. **Change:** env template destination from `{{ yak_app_path }}/.env` to `/etc/yak/env`
7. **Change:** container image from `yak:latest` to `ghcr.io/geocodio/yak:latest`

### Env file

Templated to `/etc/yak/env` on the host, passed to the container via `env_file`. The entrypoint already removes `/app/.env` inside the container so Laravel reads from environment variables.

### Rollback

Override the image tag variable and re-run Ansible:
```
ansible-playbook ansible/playbook.yml --tags yak-container -e yak_image_tag=abc1234
```

### New vault variable

`ghcr_token` — a GitHub Personal Access Token with `read:packages` scope, used by Ansible to pull images from ghcr.io on the server.

## Out of scope

- Automated deploy on push (Ansible is still manually triggered)
- Multi-arch builds (server is amd64 only)
- Image pruning/cleanup on the server
