#!/usr/bin/env bash
# Optional: copy to .deploy/deploy.sh in the theme/plugin repository.
# Runs from the repository folder after each successful checkout, as whichever
# user performed the deploy (PHP user for webhooks/admin, your user for WP-CLI).
# Env: GDW_REPO_ID, GDW_REPO_DIR, WP_ROOT. A non-zero exit marks the deploy FAILED.
set -euo pipefail

# composer install --no-dev --optimize-autoloader --no-interaction
# command -v wp >/dev/null && wp cache flush --path="$WP_ROOT"

echo "post-deploy hook finished for $GDW_REPO_ID"
