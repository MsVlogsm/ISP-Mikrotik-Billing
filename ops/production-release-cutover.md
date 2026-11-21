# Production release layout cutover

This runbook is deliberately separate from the deployment workflow. Do not run a cutover while the production host is unreachable or without confirming the active release and runtime paths.

## Target layout

- Nginx HTTPS document root: /var/www/isp-billing-releases/current/public
- Immutable code releases: /var/www/isp-billing-releases/<timestamp>-<short-sha>
- Stable pointer: /var/www/isp-billing-releases/current (atomic symlink replacement)
- Shared runtime: /var/www/isp-billing-shared/.env and /var/www/isp-billing-shared/storage
- Root-owned deployment helper: /usr/local/sbin/xlink-billing-deploy
- Root-owned mode marker: /etc/xlink-billing/deploy-mode containing exactly release-v1

## One-time migration checklist

1. Read the active HTTPS vhost with nginx -T. Record the exact document root, PHP-FPM socket, active release path, current .env target and current storage target. Do not infer them from directory names.
2. Back up the current active release and database. Verify the backup archive and database dump before changing paths.
3. Create the shared runtime directory. Copy the active release's actual .env and storage into it with ownership and restrictive permissions preserved. Do not overwrite an existing shared runtime directory; reconcile it first.
4. Replace only the active release's .env and storage entries with symlinks to the shared runtime. Verify the application user can read .env and write required storage subdirectories.
5. Create current as a symlink to the exact release currently served. Verify resolving current/public yields the same real directory as the former Nginx document root.
6. Change the HTTPS vhost root to /var/www/isp-billing-releases/current/public. Keep the PHP-FPM socket at PHP 8.5 and use $realpath_root$fastcgi_script_name for SCRIPT_FILENAME. Run nginx -t before reload; after reload, verify HTTPS login and active release still work.
7. Install a root-owned, non-group/world-writable release-aware helper at /usr/local/sbin/xlink-billing-deploy. It must embed the exact version marker XLINK_RELEASE_DEPLOY_V1, validate the tested SHA, build a new release without modifying the active release, preserve the shared runtime, take and verify backups, and atomically switch current only after candidate checks succeed.
8. The helper must keep the previous target and atomically restore it if the HTTPS health check fails. Retain previous releases for rollback; do not prune the active or rollback target.
9. Write the root-owned mode marker release-v1 only after the helper and server layout have both been verified.
10. Run sudo bash ops/verify-production-release-layout.sh. The production workflow remains blocked until this succeeds.

## Release transaction requirements

- Build under a unique new release directory; never rsync with --delete into the live release.
- Reuse the stable shared .env and storage paths. Do not copy secrets into release artifacts.
- Run Composer install and candidate-specific config/route cache preparation before switching. Because storage is shared, generate the Blade view cache only after the atomic switch; if rollback occurs, regenerate the previous release's view cache.
- Back up and verify the production database before any approved migration. Migrations must be reviewed for backward compatibility; this workflow must not be used to approve a schema change by itself.
- Use an atomic temporary symlink plus rename (mv -Tf) for the current switch.
- Health-check https://bill.xlinkbd.net/login through the local HTTPS vhost with TLS verification. On failure, restore the previous symlink and re-check health.
- Keep deployment logs free of .env, credentials, customer records, and SQL dump contents.
