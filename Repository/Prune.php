<?php

namespace HappyBoard\ConvoPlus\Repository;

use HappyBoard\Misc\AutoPrune\Audit;
use HappyBoard\Misc\AutoPrune\HoldChecker;
use HappyBoard\Misc\AutoPrune\Lock;
use HappyBoard\Misc\AutoPrune\Policy;
use XF\Mvc\Entity\Repository;

class Prune extends Repository
{
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
                $conversation = $this->em->find('XF:ConversationMaster', $conversationId);
                $restoreValues = ['hb_pruned_date' => 0, 'hb_prune_clock' => \XF::$time];
                if (isset($conversation->structure()->columns['hb_pruned_date'], $conversation->structure()->columns['hb_prune_clock']))
                {
                    $conversation->fastUpdate($restoreValues);
                }
                else
                {
                    // XF disables this add-on's entity extensions before calling Setup::uninstall().
                    // The locked raw stamp still proves ownership while the core structure lacks our columns.
                    $db->update('xf_conversation_master', $restoreValues,
                        'conversation_id = ? AND hb_pruned_date = ?', [$conversationId, (int)$record['pruned_date']]);
                    $this->em->detachEntity($conversation);
                    $conversation = $this->em->find('XF:ConversationMaster', $conversationId);
                }
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
            try
            {
                $this->app()->search()->index('conversation', $conversation);
                $conversation->getBehavior('XF:IndexableContainer')->triggerReindex();
            }
            catch (\Throwable $e)
            {
                \XF::logException($e, false, 'Restored conversation search rebuild failed (' . $conversationId . '): ');
            }
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
        $this->db()->update('xf_hb_convo_prune', [
            'state' => 'cancelled', 'hold_reason' => '', 'resolved_date' => \XF::$time,
        ], 'conversation_id = ?', $conversationId);
        (new Audit($this->app()))->log('conversation', $conversationId, 'cancel', ['reason' => $reason]);
    }

    public function refreshHolds(): int
    {
        $changed = 0;
        $checker = new HoldChecker($this->app());
        $records = $this->db()->fetchAll("SELECT p.*, c.hb_pruned_date FROM xf_hb_convo_prune p
            LEFT JOIN xf_conversation_master c ON (c.conversation_id = p.conversation_id)
            WHERE p.state IN ('pending', 'held') ORDER BY p.conversation_id");
        foreach ($records AS $record)
        {
            $id = (int)$record['conversation_id'];
            if ((int)$record['hb_pruned_date'] !== (int)$record['pruned_date'] || !(int)$record['pruned_date'])
            {
                $this->cancel($id, 'prune_stamp_changed');
                $changed++;
                continue;
            }
            $reason = $checker->conversation($id);
            if ($reason)
            {
                $this->setHeld($id, $reason);
            }
            else if ($record['state'] === 'held' && (int)$record['purge_attempts'] < 3)
            {
                $this->db()->update('xf_hb_convo_prune', ['state' => 'pending', 'hold_reason' => ''], 'conversation_id = ?', $id);
                (new Audit($this->app()))->log('conversation', $id, 'release');
                $changed++;
            }
        }
        return $changed;
    }

    public function releaseHold(int $conversationId, int $userId = 0): bool
    {
        return (bool)(new Lock($this->db()))->run('hbConvoPrune', function () use ($conversationId, $userId) {
            $record = $this->findRecord($conversationId);
            if (!$record || $record['state'] !== 'held' || (new HoldChecker($this->app()))->conversation($conversationId))
            {
                return false;
            }
            $this->db()->update('xf_hb_convo_prune', ['state' => 'pending', 'hold_reason' => '', 'purge_attempts' => 0], 'conversation_id = ?', $conversationId);
            (new Audit($this->app()))->log('conversation', $conversationId, 'release', [], $userId);
            return true;
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
