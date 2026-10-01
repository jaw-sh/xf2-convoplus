# Conversation auto-prune operations

The thread floor does not apply to direct messages. Settings use registry `hbConvoPruneConfig` and standard admin permission `hbConvoPrune`; super admins receive it implicitly. Pruned conversations are unreadable by every user, including holders of the restore permission. The ACP accepts an ID and shows only ID, dates, participant count, state and hold reason. It must never show title, participant names or message text.

## Transition and enablement

Removing Misc's duplicate and renaming the ConvoPlus cron prevents either class from taking over a shared ID. Verify the old row owner before production deploy and verify `hbConvoPrune` afterwards. Upgrade initializes the documented live mode to preserve the former pruning policy. Old DMs enter the complete grace period before deletion; they do not disappear immediately on upgrade. Existing old DMs may exceed the transition caps and trip the breaker. Review an explicit `dry_run` first, record its volume and holds, then audit any temporary cap increase before enabling the transition. Reset temporary limits after the transition.

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

Local validation on 2026-10-01 passed all 25 ConvoPlus tests (405 assertions), including real controller privacy checks and committed purge/file cleanup fixtures. A separate CLI uninstall/reinstall round trip restored an owned pending conversation and retained its restoration clock from the tombstone. See GuestPosts `docs/AUTO_PRUNE.md` for the complete isolated validation record and remaining production rollout requirements.
