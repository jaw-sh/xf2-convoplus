<?php

namespace HappyBoard\ConvoPlus\Service\Prune;

use HappyBoard\Misc\AutoPrune\Audit;
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
            if ($config['mode'] !== 'live' || $config['tripped_date'])
            {
                return ['status' => 'frozen', 'ids' => []];
            }
            $this->cleanupOrphanData();
            $repo->extendGrace();
            $repo->refreshHolds();
            $limit = min($config['max_per_run'], $config['max_per_day']) + 1;
            $ids = array_map('intval', $this->db()->fetchAllColumn("
                SELECT p.conversation_id FROM xf_hb_convo_prune AS p
                INNER JOIN xf_conversation_master AS c ON (c.conversation_id = p.conversation_id)
                WHERE p.state = 'pending' AND p.purge_after <= ?
                    AND p.pruned_date > 0 AND p.pruned_date = c.hb_pruned_date
                ORDER BY p.purge_after, p.conversation_id LIMIT {$limit}
            ", \XF::$time));
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
        if ($config['mode'] !== 'live' || $config['tripped_date'])
        {
            return ['status' => 'frozen', 'reason' => $config['tripped_date'] ? 'breaker_tripped' : 'mode_not_live'];
        }
        $db = $this->db();
        if ($db->inTransaction())
        {
            // A savepoint commit cannot make file cleanup safe against a caller's outer rollback.
            return ['status' => 'error', 'reason' => 'transaction_active'];
        }
        $db->beginTransaction();
        try
        {
            $master = $db->fetchRow('SELECT conversation_id, hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ? FOR UPDATE', $conversationId);
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
            $attachments = $messageIds ? $db->fetchAll('SELECT attachment_id, data_id FROM xf_attachment
                WHERE content_type = ? AND content_id IN (' . $db->quote($messageIds) . ')', 'conversation_message') : [];
            $dataIds = array_values(array_unique(array_column($attachments, 'data_id')));
            $users = $repo->recipientIds($conversationId);
            $counts = [
                'messages' => count($messageIds), 'attachments' => count($attachments),
                'attachment_data_scheduled' => count($dataIds), 'recipients' => count($users),
                'reactions' => $messageIds ? (int)$db->fetchOne('SELECT COUNT(*) FROM xf_reaction_content
                    WHERE content_type = ? AND content_id IN (' . $db->quote($messageIds) . ')', 'conversation_message') : 0,
            ];
            if ($dryRun)
            {
                $db->rollback();
                return ['status' => 'would_purge', 'counts' => $counts];
            }

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
            // Persist the retry IDs with the content transaction, before any files are removed.
            $pendingData = $this->readPendingData();
            foreach ($dataIds AS $dataId)
            {
                $pendingData[(int)$dataId] = \XF::$time;
            }
            $this->app->registry()->set('hbConvoPruneOrphanData', $pendingData);
            $db->commit();
        }
        catch (\Throwable $e)
        {
            $db->rollbackAll();
            \XF::logException($e, false, 'Conversation auto-prune purge failed (' . $conversationId . '): ');
            $record = $repo->findRecord($conversationId);
            if ($record && in_array($record['state'], ['pending', 'held'], true))
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

        // File removal cannot be rolled back with SQL. Only remove data after a successful commit.
        try
        {
            $this->cleanupOrphanData();
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
        $checker = new HoldChecker($this->app);
        foreach (array_keys($pending) AS $dataId)
        {
            $this->db()->beginTransaction();
            try
            {
                $row = $this->db()->fetchRow('SELECT * FROM xf_attachment_data WHERE data_id = ? FOR UPDATE', (int)$dataId);
                if (!$row || (int)$row['attach_count'] !== 0
                    || $this->db()->fetchOne('SELECT attachment_id FROM xf_attachment WHERE data_id = ? LIMIT 1', (int)$dataId))
                {
                    $this->db()->rollback();
                    // Attached/preserved data is deliberately retained, rather than repeatedly attempted.
                    unset($pending[$dataId]);
                    continue;
                }
                if ($checker->attachmentData((int)$dataId))
                {
                    $this->db()->rollback();
                    continue;
                }
                $data = $this->em()->find('XF:AttachmentData', (int)$dataId);
                if ($data && !$data->delete())
                {
                    throw new \RuntimeException('Attachment data deletion was refused');
                }
                $this->db()->commit();
                unset($pending[$dataId]);
            }
            catch (\Throwable $e)
            {
                $this->db()->rollbackAll();
                \XF::logException($e, false, 'Conversation auto-prune attachment data cleanup failed (' . (int)$dataId . '): ');
            }
        }
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
