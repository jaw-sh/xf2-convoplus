<?php

namespace HappyBoard\ConvoPlus\Service\Prune;

use HappyBoard\Misc\AutoPrune\Audit;
use HappyBoard\Misc\AutoPrune\AttachmentCleanup;
use HappyBoard\Misc\AutoPrune\HoldChecker;
use HappyBoard\Misc\AutoPrune\Lock;
use HappyBoard\Misc\AutoPrune\Policy;
use XF\Service\AbstractService;

class Purger extends AbstractService
{
    public function prepareBatch(): array
    {
        return (new Lock($this->db()))->run('hbConvoPrune', function () {
            $policy = new Policy($this->app);
            if ($policy->isDisabled())
            {
                return ['status' => 'disabled', 'ids' => []];
            }
            $repo = $this->repository('HappyBoard\ConvoPlus:Prune');
            $config = $repo->getConfig();
            // Repair stale ownership or activity even when destructive processing is frozen.
            $repo->refreshHolds($config['mode'] !== 'live' || (bool)$config['tripped_date']);
            if ($config['mode'] !== 'live' || $config['tripped_date'])
            {
                return ['status' => 'frozen', 'ids' => []];
            }
            $this->cleanupOrphanData();
            $repo->extendGrace();
            $limit = min($config['max_per_run'], $config['max_per_day']) + 1;
            $ids = array_map('intval', $this->db()->fetchAllColumn("
                SELECT p.conversation_id FROM xf_hb_convo_prune AS p
                INNER JOIN xf_conversation_master AS c ON (c.conversation_id = p.conversation_id)
                WHERE p.state = 'pending' AND p.purge_after <= ?
                    AND p.pruned_date > 0 AND p.pruned_date = c.hb_pruned_date
                    AND GREATEST(c.start_date, c.last_message_date, c.hb_prune_clock) < ?
                ORDER BY p.purge_after, p.conversation_id LIMIT {$limit}
            ", [\XF::$time, \XF::$time - $config['inactivity_days'] * 86400]));
            if (Policy::exceedsCap(count($ids), 0, $this->purgedToday(), $config['max_per_run'], $config['max_per_day']))
            {
                $repo->trip('purge_cap', count($ids));
                return ['status' => 'tripped', 'ids' => []];
            }
            return ['status' => 'ready', 'ids' => $ids];
        }) ?? ['status' => 'busy', 'ids' => []];
    }

    public function run(): array
    {
        $batch = $this->prepareBatch();
        $batch['results'] = [];
        $purged = 0;
        foreach ($batch['ids'] AS $id)
        {
            $result = $this->purge($id, false, false, $purged);
            $batch['results'][$id] = $result;
            $purged += $result['status'] === 'purged' ? 1 : 0;
            if (in_array($result['status'], ['frozen', 'disabled'], true))
            {
                break;
            }
        }
        return $batch;
    }

    /** Manual callers receive the exact automatic guards, including pending ownership and grace. */
    public function purge(int $conversationId, bool $dryRun = false, bool $manual = false, int $runCount = 0): array
    {
        return (new Lock($this->db()))->run('hbConvoPrune', function () use ($conversationId, $dryRun, $runCount) {
            return $this->purgeLocked($conversationId, $dryRun, $runCount);
        }) ?? ['status' => 'busy'];
    }

    protected function purgeLocked(int $conversationId, bool $dryRun, int $runCount): array
    {
        $policy = new Policy($this->app);
        if ($policy->isDisabled())
        {
            return ['status' => 'disabled'];
        }
        $repo = $this->repository('HappyBoard\ConvoPlus:Prune');
        $config = $repo->getConfig();
        $db = $this->db();
        if ($db->inTransaction())
        {
            // A savepoint commit cannot make file cleanup safe against a caller's outer rollback.
            return ['status' => 'error', 'reason' => 'transaction_active'];
        }
        $attachmentCleanup = null;
        $db->beginTransaction();
        try
        {
            $master = $db->fetchRow('SELECT conversation_id, hb_pruned_date, start_date, last_message_date, hb_prune_clock
                FROM xf_conversation_master WHERE conversation_id = ? FOR UPDATE', $conversationId);
            $record = $db->fetchRow('SELECT * FROM xf_hb_convo_prune WHERE conversation_id = ? FOR UPDATE', $conversationId);
            if (!$record || !in_array($record['state'], ['pending', 'held'], true))
            {
                $db->rollback();
                return ['status' => 'not_pending'];
            }
            if (!self::hasOwnedStamp($record, $master ?: null))
            {
                if (!$dryRun)
                {
                    $repo->cancel($conversationId, 'prune_stamp_changed');
                    $db->commit();
                }
                else
                {
                    $db->rollback();
                }
                return ['status' => 'cancelled', 'reason' => 'prune_stamp_changed'];
            }
            $activityClock = max((int)$master['start_date'], (int)$master['last_message_date'], (int)$master['hb_prune_clock']);
            $activityReason = $activityClock >= \XF::$time - $config['inactivity_days'] * 86400
                ? 'not_inactive' : ($activityClock >= (int)$record['pruned_date'] ? 'activity_after_prune' : null);
            if ($activityReason)
            {
                if (!$dryRun)
                {
                    $repo->cancel($conversationId, $activityReason);
                    $db->commit();
                }
                else
                {
                    $db->rollback();
                }
                return ['status' => 'cancelled', 'reason' => $activityReason];
            }
            if ($config['mode'] !== 'live' || $config['tripped_date'])
            {
                $db->rollback();
                return ['status' => 'frozen', 'reason' => $config['tripped_date'] ? 'breaker_tripped' : 'mode_not_live'];
            }
            $hold = (new HoldChecker($this->app))->conversation($conversationId);
            if ($hold || $record['state'] === 'held')
            {
                if (!$dryRun && $hold)
                {
                    $repo->setHeld($conversationId, $hold);
                    $db->commit();
                }
                else
                {
                    $db->rollback();
                }
                return ['status' => 'held', 'reason' => $hold ?: $record['hold_reason']];
            }
            $purgeAfter = Policy::extendGrace((int)$record['purge_after'], (int)$record['pruned_date'], $config['grace_days']);
            if ($purgeAfter > \XF::$time)
            {
                if (!$dryRun && $purgeAfter > (int)$record['purge_after'])
                {
                    $db->update('xf_hb_convo_prune', ['purge_after' => $purgeAfter], 'conversation_id = ?', $conversationId);
                    $db->commit();
                }
                else
                {
                    $db->rollback();
                }
                return ['status' => 'not_due', 'purge_after' => $purgeAfter];
            }
            if (Policy::exceedsCap(1, $runCount, $this->purgedToday(), $config['max_per_run'], $config['max_per_day']))
            {
                $db->rollback();
                if (!$dryRun)
                {
                    $repo->trip('purge_cap', 1);
                }
                return ['status' => 'frozen', 'reason' => 'purge_cap'];
            }

            $messageIds = array_map('intval', $db->fetchAllColumn('SELECT message_id FROM xf_conversation_message WHERE conversation_id = ?', $conversationId));
            $attachments = $messageIds ? $db->fetchAll('SELECT * FROM xf_attachment
                WHERE content_type = ? AND content_id IN (' . $db->quote($messageIds) . ') FOR UPDATE', 'conversation_message') : [];
            $dataIds = array_values(array_unique(array_column($attachments, 'data_id')));
            $users = $repo->recipientIds($conversationId);
            $counts = [
                'messages' => count($messageIds), 'attachments' => count($attachments),
                'attachment_data_scheduled' => count($dataIds), 'recipients' => count($users),
                'reactions' => $messageIds ? (int)$db->fetchOne('SELECT COUNT(*) FROM xf_reaction_content
                    WHERE content_type = ? AND content_id IN (' . $db->quote($messageIds) . ')', 'conversation_message') : 0,
            ];
            AttachmentCleanup::assertCapabilities($this->app);
            if ($dryRun)
            {
                $db->rollback();
                return ['status' => 'would_purge', 'counts' => $counts];
            }

            $attachmentCleanup = AttachmentCleanup::begin($this->app, $attachments);
            foreach ($attachments AS $row)
            {
                $attachment = $this->em()->find('XF:Attachment', (int)$row['attachment_id']);
                if ($attachment && !$attachment->delete())
                {
                    throw new \RuntimeException('Attachment deletion was refused');
                }
            }
            $this->cleanupContent($conversationId, $messageIds);
            $conversation = $this->em()->find('XF:ConversationMaster', $conversationId);
            if (!$conversation || !$conversation->delete())
            {
                throw new \RuntimeException('Conversation deletion was refused');
            }
            // Core decrements unread again on deletion; rebuild from the surviving visible masters.
            $repo->rebuildUnread($users);
            $db->update('xf_hb_convo_prune', [
                'state' => 'purged', 'hold_reason' => '', 'resolved_date' => \XF::$time,
            ], 'conversation_id = ?', $conversationId);
            (new Audit($this->app))->log('conversation', $conversationId, 'purge', $counts);
            // Persist mirror and remote cleanup metadata before committing the content removal.
            $attachmentCleanup->queue();
            $db->commit();
        }
        catch (\Throwable $e)
        {
            $db->rollbackAll();
            $this->em()->clearEntityCache();
            \XF::logException($e, false, 'Conversation auto-prune purge failed (' . $conversationId . '): ');
            $record = $repo->findRecord($conversationId);
            if (!$dryRun && $record && in_array($record['state'], ['pending', 'held'], true))
            {
                $attempts = min(255, (int)$record['purge_attempts'] + 1);
                $db->update('xf_hb_convo_prune', ['purge_attempts' => $attempts], 'conversation_id = ?', $conversationId);
                if ($attempts >= 3)
                {
                    $repo->setHeld($conversationId, 'purge_error');
                }
            }
            return ['status' => 'error', 'reason' => 'purge_error'];
        }
        finally
        {
            if ($attachmentCleanup)
            {
                $attachmentCleanup->close();
            }
        }

        // File removal cannot be rolled back with SQL. Only remove data after a successful commit.
        try
        {
            $attachmentCleanup->cleanup();
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Conversation auto-prune deferred data cleanup: ');
        }
        return ['status' => 'purged', 'counts' => $counts];
    }

    public static function hasOwnedStamp(array $record, ?array $master): bool
    {
        return $master !== null && (int)$record['pruned_date'] > 0
            && (int)$master['hb_pruned_date'] === (int)$record['pruned_date'];
    }

    protected function purgedToday(): int
    {
        return (new Audit($this->app))->countAction('conversation', 'purge', \XF::$time - 86400);
    }

    protected function cleanupContent(int $conversationId, array $messageIds): void
    {
        $db = $this->db();
        foreach (['xf_user_alert', 'xf_edit_history', 'xf_content_spam_cache'] AS $table)
        {
            if ($messageIds)
            {
                $db->delete($table, 'content_type = ? AND content_id IN (' . $db->quote($messageIds) . ')', 'conversation_message');
            }
            $db->delete($table, 'content_type = ? AND content_id = ?', ['conversation', $conversationId]);
        }
        $db->delete('xf_draft', 'draft_key IN (' . $db->quote([
            'conversation-' . $conversationId, 'conversation-reply-' . $conversationId,
        ]) . ')');
    }

    public function cleanupOrphanData(): void
    {
        (new Lock($this->db()))->run('hbConvoPrune', function () {
            $this->cleanupOrphanDataLocked();
        });
    }

    protected function cleanupOrphanDataLocked(): void
    {
        if ((new Policy($this->app))->isDisabled())
        {
            return;
        }
        if ($this->db()->inTransaction())
        {
            // Never delete bytes while another caller still owns the content transaction.
            return;
        }
        $pending = $this->readPendingData();
        if (!$pending)
        {
            return;
        }
        $result = AttachmentCleanup::cleanupDataIds($this->app, array_keys($pending));
        $pending = array_intersect_key($pending, array_fill_keys($result['failed'], true));
        $this->app->registry()->set('hbConvoPruneOrphanData', $pending);
    }

    protected function readPendingData(): array
    {
        $value = $this->db()->fetchOne('SELECT data_value FROM xf_data_registry WHERE data_key = ?', 'hbConvoPruneOrphanData');
        if ($value === false || $value === null)
        {
            return [];
        }
        $pending = @unserialize($value, ['allowed_classes' => false]);
        if (!is_array($pending))
        {
            throw new \UnexpectedValueException('Invalid conversation auto-prune attachment cleanup queue.');
        }
        return $pending;
    }
}
