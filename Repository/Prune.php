<?php

namespace HappyBoard\ConvoPlus\Repository;

use HappyBoard\Misc\AutoPrune\Audit;
use HappyBoard\Misc\AutoPrune\HoldChecker;
use HappyBoard\Misc\AutoPrune\Lock;
use HappyBoard\Misc\AutoPrune\Policy;
use XF\Mvc\Entity\Repository;

class Prune extends Repository
{
    /** Matches GuestPosts threads: the maximum UINT is a permanent, moderator-managed exemption. */
    public const PERMANENT_EXEMPTION = 4294967295;

    public static function isExemptValue($exemptUntil): bool
    {
        return (int)$exemptUntil >= \XF::$time;
    }

    public function getConfig(): array
    {
        return (new Policy($this->app()))->conversationConfig();
    }

    public function saveConfig(array $input, int $userId = 0): array
    {
        $saved = (new Lock($this->db()))->run('hbConvoPrune', function () use ($input, $userId) {
            (new Policy($this->app()))->saveConversationConfig($input, $userId);
            $this->extendGrace();
            return true;
        });
        if ($saved !== true)
        {
            throw new \InvalidArgumentException('Conversation auto-prune is busy. Retry the settings change.');
        }
        return $this->getConfig();
    }

    public function findRecord(int $conversationId): ?array
    {
        return $this->db()->fetchRow('SELECT * FROM xf_hb_convo_prune WHERE conversation_id = ?', $conversationId) ?: null;
    }

    /** Deliberately never selects titles, messages, recipient names or IDs. */
    public function metadata(int $conversationId): ?array
    {
        return $this->db()->fetchRow('
            SELECT p.*, c.start_date, c.last_message_date, c.recipient_count, c.hb_pruned_date
            FROM xf_hb_convo_prune AS p
            LEFT JOIN xf_conversation_master AS c ON (c.conversation_id = p.conversation_id)
            WHERE p.conversation_id = ?
        ', $conversationId) ?: null;
    }

    /**
     * Moderator-facing metadata for any existing conversation, pruned or not.
     * Deliberately never selects titles, messages, recipient names or IDs, and
     * collapses pending/held so hold existence is not revealed.
     */
    public function exemptionMetadata(int $conversationId): ?array
    {
        $row = $this->db()->fetchRow('
            SELECT c.conversation_id, c.start_date, c.last_message_date, c.recipient_count,
                c.hb_pruned_date, c.hb_prune_exempt_until, p.state
            FROM xf_conversation_master AS c
            LEFT JOIN xf_hb_convo_prune AS p ON (p.conversation_id = c.conversation_id)
            WHERE c.conversation_id = ?
        ', $conversationId);
        if (!$row)
        {
            return null;
        }
        $pending = (int)$row['hb_pruned_date'] > 0 && in_array($row['state'], ['pending', 'held'], true);
        return [
            'conversation_id' => (int)$row['conversation_id'],
            'start_date' => (int)$row['start_date'],
            'last_message_date' => (int)$row['last_message_date'],
            'recipient_count' => (int)$row['recipient_count'],
            'pending_deletion' => $pending,
            'exempt' => self::isExemptValue($row['hb_prune_exempt_until']),
        ];
    }

    /**
     * Sets or clears the permanent exemption. Exempting a conversation that is
     * pending deletion (or held) also restores it, through the same guarded
     * restore path the ACP uses. Returns null when the lock is busy.
     */
    public function setExempt(int $conversationId, bool $exempt, int $userId = 0): ?bool
    {
        $result = (new Lock($this->db()))->run('hbConvoPrune', function () use ($conversationId, $exempt, $userId) {
            $db = $this->db();
            $db->beginTransaction();
            try
            {
                $master = $db->fetchRow('SELECT conversation_id, hb_pruned_date, hb_prune_exempt_until
                    FROM xf_conversation_master WHERE conversation_id = ? FOR UPDATE', $conversationId);
                if (!$master)
                {
                    $db->rollback();
                    return false;
                }
                $value = $exempt ? self::PERMANENT_EXEMPTION : 0;
                $changed = (int)$master['hb_prune_exempt_until'] !== $value;
                if ($changed)
                {
                    $values = ['hb_prune_exempt_until' => $value];
                    if (!$exempt)
                    {
                        // Removing an exemption starts a fresh inactivity window rather than
                        // making a long-quiet conversation eligible on the very next run.
                        $values['hb_prune_clock'] = \XF::$time;
                    }
                    $db->update('xf_conversation_master', $values, 'conversation_id = ?', $conversationId);
                    (new Audit($this->app()))->log('conversation', $conversationId, 'exempt', ['exempt' => $exempt], $userId);
                }
                $db->commit();
            }
            catch (\Throwable $e)
            {
                $db->rollback();
                throw $e;
            }

            $restored = false;
            if ($exempt && (int)$master['hb_pruned_date'] > 0)
            {
                $record = $this->findRecord($conversationId);
                if ($record && in_array($record['state'], ['pending', 'held'], true))
                {
                    $restored = $this->restore($conversationId, $userId);
                }
                if (!$restored)
                {
                    // A stale or unowned stamp: cancelling clears it and rescues the intact conversation.
                    $this->cancel($conversationId, 'exempt');
                }
            }

            $entity = $this->em->findCached('XF:ConversationMaster', $conversationId);
            if ($entity)
            {
                $this->em->detachEntity($entity);
            }
            return true;
        });
        return $result === null ? null : (bool)$result;
    }

    public function countExempt(): int
    {
        return (int)$this->db()->fetchOne('SELECT COUNT(*) FROM xf_conversation_master WHERE hb_prune_exempt_until >= ?', \XF::$time);
    }

    public function queue(int $limit = 250): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->db()->fetchAll("
            SELECT p.*, c.start_date, c.last_message_date, c.recipient_count, c.hb_pruned_date
            FROM xf_hb_convo_prune AS p
            LEFT JOIN xf_conversation_master AS c ON (c.conversation_id = p.conversation_id)
            WHERE p.state IN ('pending', 'held')
            ORDER BY p.purge_after, p.conversation_id
            LIMIT {$limit}
        ");
    }

    public function extendGrace(): void
    {
        $seconds = $this->getConfig()['grace_days'] * 86400;
        $this->db()->query("
            UPDATE xf_hb_convo_prune SET purge_after = GREATEST(purge_after, pruned_date + ?)
            WHERE state IN ('pending', 'held')
        ", $seconds);
    }

    public function recipientIds(int $conversationId): array
    {
        return array_map('intval', $this->db()->fetchAllColumn('
            SELECT owner_user_id FROM xf_conversation_user WHERE conversation_id = ?
            UNION SELECT user_id FROM xf_conversation_recipient WHERE conversation_id = ?
        ', [$conversationId, $conversationId]));
    }

    /** Both incremental prune/restore and repair jobs use the same visibility rule. */
    public function rebuildUnread(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds)
        {
            return;
        }
        $this->db()->query('
            UPDATE xf_user AS u SET conversations_unread = (
                SELECT LEAST(65535, COUNT(*)) FROM xf_conversation_user AS cu
                INNER JOIN xf_conversation_master AS cm ON (cm.conversation_id = cu.conversation_id)
                WHERE cu.owner_user_id = u.user_id AND cu.is_unread = 1 AND cm.hb_pruned_date = 0
            ) WHERE u.user_id IN (' . $this->db()->quote($userIds) . ')
        ');
        foreach ($userIds AS $userId)
        {
            // Counts can already be loaded in the ACP request or a CLI process.
            $user = $this->em->find('XF:User', $userId);
            if ($user)
            {
                $count = (int)$this->db()->fetchOne('SELECT conversations_unread FROM xf_user WHERE user_id = ?', $userId);
                $user->fastUpdate('conversations_unread', $count);
            }
        }
    }

    public function restore(int $conversationId, int $userId = 0): bool
    {
        return (bool)(new Lock($this->db()))->run('hbConvoPrune', function () use ($conversationId, $userId) {
            $db = $this->db();
            $db->beginTransaction();
            try
            {
                $master = $db->fetchRow('SELECT conversation_id, hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ? FOR UPDATE', $conversationId);
                $record = $db->fetchRow('SELECT * FROM xf_hb_convo_prune WHERE conversation_id = ? FOR UPDATE', $conversationId);
                if (!$master || !$record || !in_array($record['state'], ['pending', 'held'], true)
                    || !(int)$record['pruned_date'] || (int)$master['hb_pruned_date'] !== (int)$record['pruned_date'])
                {
                    $db->rollback();
                    return false;
                }
                $users = $this->recipientIds($conversationId);
                $conversation = $this->clearPruneMarker($conversationId, (int)$record['pruned_date']);
                $db->update('xf_hb_convo_prune', [
                    'state' => 'restored', 'hold_reason' => '', 'resolved_date' => \XF::$time,
                    'resolved_user_id' => $userId, 'purge_attempts' => 0,
                ], 'conversation_id = ?', $conversationId);
                $this->rebuildUnread($users);
                (new Audit($this->app()))->log('conversation', $conversationId, 'restore', [], $userId);
                $db->commit();
            }
            catch (\Throwable $e)
            {
                $db->rollback();
                throw $e;
            }
            // Rebuild indexing after the visibility change has committed.
            $this->reindexRecoveredConversation($conversation);
            return true;
        });
    }

    public function setHeld(int $conversationId, string $reason): void
    {
        $record = $this->findRecord($conversationId);
        if (!$record || !in_array($record['state'], ['pending', 'held'], true))
        {
            return;
        }
        if ($record['state'] === 'held' && $record['hold_reason'] === $reason)
        {
            return;
        }
        $this->db()->update('xf_hb_convo_prune', ['state' => 'held', 'hold_reason' => $reason], 'conversation_id = ?', $conversationId);
        (new Audit($this->app()))->log('conversation', $conversationId, 'hold', ['reason' => $reason]);
    }

    public function cancel(int $conversationId, string $reason): void
    {
        $result = (new Lock($this->db()))->run('hbConvoPrune', function () use ($conversationId, $reason) {
            $db = $this->db();
            $db->beginTransaction();
            $conversation = null;
            try
            {
                $master = $db->fetchRow('SELECT conversation_id, hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ? FOR UPDATE', $conversationId);
                $record = $db->fetchRow('SELECT * FROM xf_hb_convo_prune WHERE conversation_id = ? FOR UPDATE', $conversationId);
                if (!$record || !in_array($record['state'], ['pending', 'held', 'cancelled'], true))
                {
                    $db->rollback();
                    return true;
                }
                if ($master && (int)$master['hb_pruned_date'] > 0)
                {
                    // An invalid stamp can never authorize deletion; clearing it rescues the intact conversation.
                    $conversation = $this->clearPruneMarker($conversationId, (int)$master['hb_pruned_date']);
                    $this->rebuildUnread($this->recipientIds($conversationId));
                }
                $db->update('xf_hb_convo_prune', [
                    'state' => 'cancelled', 'hold_reason' => '', 'resolved_date' => \XF::$time,
                    'purge_attempts' => 0,
                ], 'conversation_id = ?', $conversationId);
                (new Audit($this->app()))->log('conversation', $conversationId, 'cancel', ['reason' => $reason]);
                $db->commit();
            }
            catch (\Throwable $e)
            {
                $db->rollback();
                throw $e;
            }
            if ($conversation)
            {
                $this->reindexRecoveredConversation($conversation);
            }
            return true;
        });
        if ($result !== true)
        {
            throw new \InvalidArgumentException('Conversation auto-prune is busy. Retry the cancellation.');
        }
    }

    protected function clearPruneMarker(int $conversationId, int $stamp): \XF\Entity\ConversationMaster
    {
        $conversation = $this->em->find('XF:ConversationMaster', $conversationId);
        $values = ['hb_pruned_date' => 0, 'hb_prune_clock' => \XF::$time];
        if (isset($conversation->structure()->columns['hb_pruned_date'], $conversation->structure()->columns['hb_prune_clock']))
        {
            $conversation->fastUpdate($values);
        }
        else
        {
            // XF disables entity extensions before Setup::uninstall(); the raw locked stamp proves ownership.
            $this->db()->update('xf_conversation_master', $values,
                'conversation_id = ? AND hb_pruned_date = ?', [$conversationId, $stamp]);
            $this->em->detachEntity($conversation);
            $conversation = $this->em->find('XF:ConversationMaster', $conversationId);
        }
        return $conversation;
    }

    protected function reindexRecoveredConversation(\XF\Entity\ConversationMaster $conversation): void
    {
        try
        {
            $this->app()->search()->index('conversation', $conversation);
            $conversation->getBehavior('XF:IndexableContainer')->triggerReindex();
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, 'Recovered conversation search rebuild failed (' . $conversation->conversation_id . '): ');
        }
    }

    public function refreshHolds(bool $recoveryOnly = false): int
    {
        return (int)((new Lock($this->db()))->run('hbConvoPrune', function () use ($recoveryOnly) {
            if ((new Policy($this->app()))->isDisabled())
            {
                return 0;
            }
            $db = $this->db();
            $changed = 0;
            $checker = new HoldChecker($this->app());
            $cutoff = \XF::$time - $this->getConfig()['inactivity_days'] * 86400;
            $ids = $db->fetchAllColumn("SELECT p.conversation_id FROM xf_hb_convo_prune p
                LEFT JOIN xf_conversation_master c ON (c.conversation_id = p.conversation_id)
                WHERE p.state IN ('pending', 'held') OR (p.state = 'cancelled' AND c.hb_pruned_date > 0)
                ORDER BY p.conversation_id");
            foreach ($ids AS $conversationId)
            {
                $id = (int)$conversationId;
                $db->beginTransaction();
                try
                {
                    if ((new Policy($this->app()))->isDisabled())
                    {
                        $db->rollback();
                        break;
                    }
                    $master = $db->fetchRow('SELECT conversation_id, hb_pruned_date, start_date, last_message_date, hb_prune_clock, hb_prune_exempt_until
                        FROM xf_conversation_master WHERE conversation_id = ? FOR UPDATE', $id);
                    $record = $db->fetchRow('SELECT * FROM xf_hb_convo_prune WHERE conversation_id = ? FOR UPDATE', $id);
                    if (!$record || !in_array($record['state'], ['pending', 'held', 'cancelled'], true))
                    {
                        $db->rollback();
                        continue;
                    }
                    $activityClock = $master
                        ? max((int)$master['start_date'], (int)$master['last_message_date'], (int)$master['hb_prune_clock']) : 0;
                    if ($record['state'] === 'cancelled')
                    {
                        if ($master && (int)$master['hb_pruned_date'] > 0)
                        {
                            $this->cancel($id, 'cancelled_stamp_recovery');
                            $changed++;
                        }
                    }
                    else if (!$master || (int)$master['hb_pruned_date'] !== (int)$record['pruned_date'] || !(int)$record['pruned_date'])
                    {
                        $this->cancel($id, 'prune_stamp_changed');
                        $changed++;
                    }
                    else if (self::isExemptValue($master['hb_prune_exempt_until']))
                    {
                        // An exemption set after selection (e.g. by raw SQL) still wins before purge.
                        $this->cancel($id, 'exempt');
                        $changed++;
                    }
                    else if ($activityClock >= $cutoff || $activityClock >= (int)$record['pruned_date'])
                    {
                        // Activity since this owned prune invalidates it even after that activity ages past the cutoff.
                        $this->cancel($id, $activityClock >= $cutoff ? 'not_inactive' : 'activity_after_prune');
                        $changed++;
                    }
                    else if (!$recoveryOnly && ($reason = $checker->conversation($id)))
                    {
                        $this->setHeld($id, $reason);
                    }
                    else if (!$recoveryOnly && $record['state'] === 'held' && (int)$record['purge_attempts'] < 3)
                    {
                        $db->update('xf_hb_convo_prune', ['state' => 'pending', 'hold_reason' => ''], 'conversation_id = ?', $id);
                        (new Audit($this->app()))->log('conversation', $id, 'release');
                        $changed++;
                    }
                    $db->commit();
                }
                catch (\Throwable $e)
                {
                    $db->rollback();
                    throw $e;
                }
            }
            return $changed;
        }) ?? 0);
    }

    public function releaseHold(int $conversationId, int $userId = 0): bool
    {
        return (bool)(new Lock($this->db()))->run('hbConvoPrune', function () use ($conversationId, $userId) {
            $db = $this->db();
            $db->beginTransaction();
            try
            {
                $master = $db->fetchRow('SELECT conversation_id, hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ? FOR UPDATE', $conversationId);
                $record = $db->fetchRow('SELECT * FROM xf_hb_convo_prune WHERE conversation_id = ? FOR UPDATE', $conversationId);
                if (!$record || $record['state'] !== 'held')
                {
                    $db->rollback();
                    return false;
                }
                if (!$master || !(int)$record['pruned_date'] || (int)$master['hb_pruned_date'] !== (int)$record['pruned_date'])
                {
                    $this->cancel($conversationId, 'prune_stamp_changed');
                    $db->commit();
                    return false;
                }
                if ($reason = (new HoldChecker($this->app()))->conversation($conversationId))
                {
                    $this->setHeld($conversationId, $reason);
                    $db->commit();
                    return false;
                }
                $db->update('xf_hb_convo_prune', ['state' => 'pending', 'hold_reason' => '', 'purge_attempts' => 0], 'conversation_id = ?', $conversationId);
                (new Audit($this->app()))->log('conversation', $conversationId, 'release', [], $userId);
                $db->commit();
                return true;
            }
            catch (\Throwable $e)
            {
                $db->rollback();
                throw $e;
            }
        });
    }

    public function trip(string $reason, int $planned): void
    {
        $policy = new Policy($this->app());
        $config = $policy->conversationConfig();
        if ($config['tripped_date'])
        {
            return;
        }
        $config['tripped_date'] = \XF::$time;
        $this->db()->beginTransaction();
        try
        {
            (new Audit($this->app()))->log('conversation', 0, 'trip', ['reason' => $reason, 'planned' => $planned]);
            $this->app()->registry()->set('hbConvoPruneConfig', $config);
            $this->db()->commit();
        }
        catch (\Throwable $e)
        {
            $this->db()->rollback();
            throw $e;
        }
        $policy->alertTrip('conversation', 0);
    }

    public function resetBreaker(int $userId = 0): void
    {
        $reset = (new Lock($this->db()))->run('hbConvoPrune', function () use ($userId) {
            $config = $this->getConfig();
            $config['tripped_date'] = 0;
            $this->db()->beginTransaction();
            try
            {
                (new Audit($this->app()))->log('conversation', 0, 'reset', [], $userId);
                $this->app()->registry()->set('hbConvoPruneConfig', $config);
                $this->db()->commit();
            }
            catch (\Throwable $e)
            {
                $this->db()->rollback();
                throw $e;
            }
            return true;
        });
        if ($reset !== true)
        {
            throw new \InvalidArgumentException('Conversation auto-prune is busy. Retry the breaker reset.');
        }
    }
}
