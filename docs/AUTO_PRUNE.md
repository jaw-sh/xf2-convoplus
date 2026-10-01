# Conversation auto-prune operations

The thread floor does not apply to direct messages. Settings use registry `hbConvoPruneConfig` and standard admin permission `hbConvoPrune`; super admins receive it implicitly. Pruned conversations are unreadable by every user, including holders of the restore permission. The ACP accepts an ID and shows only ID, dates, participant count, state and hold reason. It must never show title, participant names or message text.

## Transition and enablement

Removing Misc's duplicate and renaming the ConvoPlus cron prevents either class from taking over a shared ID. Verify the old row owner before production deploy and verify `hbConvoPrune` afterwards. Upgrade initializes the documented live mode to preserve the former pruning policy. Old DMs enter the complete grace period before deletion; they do not disappear immediately on upgrade. Existing old DMs may exceed the transition caps and trip the breaker. For a reviewed transition, set `$config['hbAutoPruneDisabled'] = true;` before installing or upgrading either pruning add-on. Keep the stop enabled while importing metadata and selecting `dry_run` on the conversation settings page. Verify `xf-hb:prune-status` reports the emergency stop and dry-run mode, then clear the stop to collect review evidence. Record volume and holds, audit any temporary cap increase, and explicitly select `live` only after review. Reset temporary limits after the transition. Without this pre-upgrade stop, the initialized `live` mode can soft-prune on the next cron; it does not wait for a review.

Production cron owner, deployment revision, dry-run evidence, transition count/cap decision and first grace/purge cycle are UNMEASURED / NOT DEPLOYED in this implementation. No production acceptance is implied. Use the thread operations guide for the emergency stop, backups, serial integration lane and audit requirements. DM purge rechecks NCMEC recipient and message-author holds, incident content/attachments, litigation recipients and open message reports under a row lock. Restore clears the prune date, records restoration and stamps inactivity immunity.

## Validation

Use PHPUnit 10/11 in `xfdev` after the installed ConvoPlus and Misc migrations. The same disposable database and file precautions as GuestPosts apply. The exact database-name acknowledgement is mandatory for mutating integration tests.

```sh
docker exec -e HB_AUTO_PRUNE_UNIT_ONLY=1 xfdev sh -c 'cd /var/www/html && php /work/phpunit.phar -c src/addons/HappyBoard/ConvoPlus/tests/phpunit.xml.dist --testsuite unit'
docker exec xfdev sh -c 'cd /var/www/html && php /work/phpunit.phar -c src/addons/HappyBoard/ConvoPlus/tests/phpunit.xml.dist --testsuite schema'
docker exec -e HB_AUTO_PRUNE_INTEGRATION=1 -e HB_AUTO_PRUNE_TEST_DB=YOUR_DISPOSABLE_DB xfdev sh -c 'cd /var/www/html && php /work/phpunit.phar -c src/addons/HappyBoard/ConvoPlus/tests/phpunit.xml.dist --testsuite integration'
```

Before production acceptance, verify a pending DM gives 410 to each participant and to staff; all reply/invite endpoints refuse it; ordinary lists and the popup exclude it; unread counts are rebuilt; the ACP ID lookup exposes only metadata; restore restores visibility/counters; holds prevent purge; grace never shortens; entity attachment deletion removes orphan files; purge removes messages/reactions/alerts/edit history/drafts/spam cache while retaining legal/audit snapshots and the tombstone. `xf-hb:destroy-conversation --dry-run` must report holds without deleting; the destructive command uses the guarded Purger. Capture `xf-hb:prune-status` before and after each transition cycle.

Copied public/CDN attachment and thumbnail URLs bypass PHP permissions. They can remain accessible during grace, including DM attachments. Purge removes origin files; CDN cache invalidation is outside this feature. This is the same residual as thread attachments and must be considered in rollout acceptance.

The original implementation run on 2026-10-01 passed 25 ConvoPlus tests (405 assertions) and a separate CLI uninstall/reinstall round trip. The subsequent review run independently passed all 35 current tests (597 assertions), including real report resolution and moderator-log rendering, inactivity/cancellation recovery, hold release and committed purge/file cleanup fixtures. EphyraMedia and XFMG were active in the isolated database. See GuestPosts `docs/AUTO_PRUNE.md` for the full review validation record and remaining production rollout requirements.

## Review hardening

Pending DM reports expose only metadata to authorized report moderators, who can resolve or reject them without reading message text, titles, participant identities or report-comment bodies. Moderator-log list/detail displays redact pending and purged DM content while retaining the stored audit evidence.

Purge rechecks the current inactivity window and clocks under row locks. Changed activity, a longer configured retention period or a cancelled record with a stale hidden marker safely restores accessibility and immunity. The ACP can release a `purge_error` hold only after checking the current ownership stamp and all actual preservation holds again.

Attachment-driven remote media deletion uses Misc's shared durable post-commit cleanup. See the thread guide for the required EphyraMedia adapter, retries and rollout checks.
