# WordPress Git Theme Management

Keep theme in sync with a GitHub branch using git. Clone the repo via WordPress Admin and trigger the
repository update using a webhook.

By Joseph Berry ([jtbez](https://github.com/jtbez)). Licensed under the GNU General Public License v2 or later.

Keeps theme and plugin folders in `wp-content` in sync with a GitHub branch using real git
(`fetch` + `checkout -f` + `clean`). Triggered by a signed webhook from GitHub or GitHub Actions,
from **Tools → Git Deploy**, or from WP-CLI.

## Setup

1. Install and activate the plugin, open **Tools → Git Deploy**, and fix anything flagged on the **Server checks** tab.
2. Click **Add repository**. Each repository is set up in five numbered tabs; a tick shows when a step is done,
   and the repository list links to the next step that still needs doing.
   1. **Repository.** Pick the folder (an existing theme or plugin, a new theme or plugin folder, or another
      folder in `wp-content`) and enter the repository URL and branch. The folder name doesn't have to match the
      GitHub repository name. If the folder is already a git checkout, its `origin` is used when the URL is left empty.
   2. **Deploy key.** For an SSH URL a deploy key is created automatically when you save step 1. Copy the public
      key, use the link to GitHub's *Add deploy key* page, paste it, and click **Test connection**. Tick
      *Allow write access* only if you'll publish from the server. HTTPS URLs need no key (public repositories only).
   3. **Set up folder** compares the folder with GitHub and offers one of two actions:
      - **Use the GitHub version**: the folder is backed up (`.tar.gz`, including any `.git`) and replaced
        by the branch. Use this for a new folder, or when GitHub is the source of truth.
      - **Send this folder to GitHub**: commits the folder and pushes it. Use this to turn an existing theme
        into a repository or to push edits made on the server. If the branch already exists on GitHub and the folder
        has no git history, the folder becomes a new commit on top of it, and files that exist only on GitHub
        (README, LICENSE) are kept. If the histories differ, publish to a new branch and merge it with a pull request,
        or force-push.
   4. **Automatic deploys.** Copy the webhook URL and secret into GitHub's *Add webhook* page (linked), or use
      `examples/deploy.yml`. Pushes to the branch now deploy automatically. Mode and other options are here too.
   5. **Deploy & history.** Deploy by hand, read the log of recent deploys, or remove the repository.

An existing `.git` folder is always detected. If its `origin` differs from the configured URL, publishing keeps
the old one as `origin-previous`. Using the repository's version backs the whole folder up first.

### Safety

- Automatic deploys (webhook, queue, `wp git-deploy pull`, Deploy now) never touch a folder that has files
  but hasn't been set up. They stop and point you to Setup.
- Local changes are normally discarded on deploy, and the discarded files are listed in the log. Tick
  *Protect local changes* to skip deploys while the folder has uncommitted edits.
- Folders must be at least two levels deep (`themes/x`, `plugins/x`); `mu-plugins` is the only exception.

## Webhook

Each repository has its own endpoint, shown with a copy button on its **Automatic deploys** tab:

```
POST https://example.com/wp-json/git-deploy/v1/<id>
```

`<id>` is the repository ID from Tools → Git Deploy. With plain permalinks the URL is
`https://example.com/?rest_route=/git-deploy/v1/<id>` instead; copying it from the admin page always gives the right form.

Every request must be signed with the repository's **secret**. The `X-Hub-Signature-256` header must be
`sha256=` followed by the hex HMAC-SHA256 of the raw request body, which is what GitHub webhooks send. Requests
with a missing or wrong signature are rejected. So are requests for an unknown or disabled repository, and all
of these get the same `401`, so the endpoint doesn't reveal which repositories exist.

### From a GitHub webhook

In the GitHub repository open **Settings → Webhooks → Add webhook** (the Automatic deploys tab links straight to it):

| Field | Value |
| --- | --- |
| Payload URL | the endpoint above |
| Content type | `application/json` (required: the body is read as JSON) |
| Secret | the repository's secret from the Automatic deploys tab |
| Events | *Just the push event* |

GitHub sends a `ping` straight away. A green tick under *Recent Deliveries* means the URL and secret are right.
After that, every push to the configured branch deploys. Pushes to other branches, and branch deletions, are
acknowledged and ignored.

### From GitHub Actions

Use this instead of a webhook when deploys should wait for build or test steps. Copy
[`examples/deploy.yml`](examples/deploy.yml) to `.github/workflows/deploy.yml` in the theme or plugin repository,
set `branches:` to the deploy branch, and add two Actions secrets: `DEPLOY_WEBHOOK_URL` (the endpoint) and
`DEPLOY_WEBHOOK_SECRET` (the secret). Use either the webhook or Actions for a repository, not both, or every push
deploys twice.

### From anything else

Any client that can sign the body can trigger a deploy. Only `ref` is required, and it must match the configured branch:

```sh
URL='https://example.com/wp-json/git-deploy/v1/my-theme'
SECRET='the-repository-secret'
PAYLOAD='{"ref":"refs/heads/main"}'
SIG=$(printf '%s' "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)
curl -fsS -X POST "$URL" -H 'Content-Type: application/json' -H "X-Hub-Signature-256: sha256=$SIG" --data-raw "$PAYLOAD"
```

Sign exactly the bytes you send: reformatting the JSON after signing breaks the signature. The optional `after`
field (a commit SHA) is only recorded. A deploy always moves the folder to the branch's current head.

### Responses

| Status | Body | Meaning |
| --- | --- | --- |
| `200` | `{"ok":true,"message":"pong"}` | GitHub `ping` event, signature valid |
| `202` | `{"ok":true,"started":"<sha>"}` | direct mode: the deploy runs after the reply |
| `202` | `{"ok":true,"queued":"<sha>"}` | queue mode: the next `run-pending` cron deploys it |
| `202` | `{"ok":true,"skipped":"…"}` | a different branch, or the branch was deleted |
| `401` | `{"ok":false,"error":"invalid signature"}` | wrong secret, unknown ID, or the repository is disabled |
| `500` | `{"ok":false,"error":"proc_open is disabled; use queue mode"}` | direct mode can't run git on this server |

A `202` means the deploy was *accepted*, not that it succeeded. In direct mode the plugin replies before running
git, because GitHub gives up after 10 seconds. Check the result on the repository's **Deploy & history** tab, with
`wp git-deploy log <id>`, or with the `gdw_after_deploy` hook.

To pause automatic deploys without removing the webhook, untick *Accept webhooks* on the Automatic deploys tab.
If you change the secret, update it on GitHub (or in the Actions secret) too.

## Modes

- **direct**: the webhook runs git as the PHP user after replying to GitHub. That user must own the folder.
- **queue**: the webhook only records a pending deploy. A cron job runs git as your deploy user:
  `* * * * * wp --path=/var/www/site1 git-deploy run-pending --quiet`

## wp-config.php (optional)

```php
define( 'GDW_KEY_DIR', '/var/www/.git-deploy-keys' );
define( 'GDW_BACKUP_DIR', '/var/www/.git-deploy-backups' );
define( 'GDW_REPOS', [            // defined repos are locked in the UI
    'site1-theme' => [
        'path'     => 'themes/site1-theme',
        'repo_url' => 'git@github.com:org/site1-theme.git',
        'branch'   => 'main',
        'mode'     => 'direct',
        'secret'   => 'long-random-string',
    ],
] );
```

## WP-CLI

```
wp git-deploy list
wp git-deploy inspect <id>
wp git-deploy setup <id> --use=repo|folder [--branch=<b>] [--message=<m>] [--force] [--no-backup] [--yes]
wp git-deploy pull [<id>...]
wp git-deploy run-pending
wp git-deploy log <id> [--count=3]
```

## Updates from GitHub

The plugin updates itself from this repository's GitHub Releases (WordPress 5.8+), with the usual
update notice, "View details" changelog, one-click update and auto-updates.

1. To release: bump `Version:` and `GDW_VERSION`, commit, then `git tag v1.2.0 && git push origin v1.2.0`.
   `.github/workflows/release.yml` checks the versions match and attaches `wordpress-git-theme-management.zip`.
2. Sites check GitHub every 6 hours (or on Dashboard → Updates → Check again).

Optional lines in the release notes are passed to WordPress: `Requires at least: 5.8`,
`Requires PHP: 7.4`, `Tested up to: 6.8`.

The updater switches itself off when the plugin folder contains `.git` (a git-deployed copy) or when
`define( 'GDW_DISABLE_UPDATER', true );` is set.

## Hooks

`do_action( 'gdw_after_deploy', $id, $ok, $trigger, $head, $output )` fires after every deploy.

## Security

- Block `.git`, `.github` and `.deploy` from the web server.
- Anyone who can push to a deploy branch can run code on the server. Protect those branches.
- Only administrators (`manage_options`) can see or change the settings.

## License

Copyright (C) 2026 Joseph Berry (jtbez)

This program is free software; you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation; either version 2 of the
License, or (at your option) any later version. See [LICENSE](LICENSE) for the full text.
