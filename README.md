# WordPress Git Theme Management

Keep theme in sync with a GitHub branch using git. Clone the repo via WordPress Admin and trigger the
repository update using a webhook.

By Joseph Berry ([jtbez](https://github.com/jtbez)). Licensed under the GNU General Public License v2 or later.

Keeps theme and plugin folders in `wp-content` in sync with a GitHub branch using real git
(`fetch` + `checkout -f` + `clean`). Triggered by a signed webhook from GitHub or GitHub Actions,
from **Tools → Git Deploy**, or from WP-CLI.

## Setup

1. Install and activate the plugin, open **Tools → Git Deploy**, and fix any red server checks.
2. **Add repository.** Pick the folder: an existing theme or plugin, a new theme or plugin folder, or another
   folder in `wp-content`. The folder name doesn't have to match the GitHub repository name. Then enter the
   repository URL and branch. If the folder is already a git checkout, its `origin` is used when the URL is left empty.
3. **Generate deploy key** and add it to the GitHub repository (Settings → Deploy keys). Tick
   *Allow write access* only if you'll publish from the server.
4. **Setup** compares the folder with GitHub and recommends one of two actions:
   - **Use the repository's version**: the folder is backed up (`.tar.gz`, including any `.git`) and replaced
     by the branch. Use this for a new folder, or when GitHub is the source of truth.
   - **Publish this folder to GitHub**: commits the folder and pushes it. Use this to turn an existing theme
     into a repository or to push edits made on the server. If the branch already exists on GitHub and the folder
     has no git history, the folder becomes a new commit on top of it, and files that exist only on GitHub
     (README, LICENSE) are kept. If the histories differ, publish to a new branch and merge it with a pull request,
     or force-push.
5. Add the GitHub webhook, or use `examples/deploy.yml`. Pushes to the branch now deploy automatically.

An existing `.git` folder is always detected. If its `origin` differs from the configured URL, publishing keeps
the old one as `origin-previous`. Using the repository's version backs the whole folder up first.

### Safety

- Automatic deploys (webhook, queue, `wp git-deploy pull`, Deploy now) never touch a folder that has files
  but hasn't been set up. They stop and point you to Setup.
- Local changes are normally discarded on deploy, and the discarded files are listed in the log. Tick
  *Protect local changes* to skip deploys while the folder has uncommitted edits.
- Folders must be at least two levels deep (`themes/x`, `plugins/x`); `mu-plugins` is the only exception.

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
