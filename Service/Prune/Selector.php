<?php

namespace HappyBoard\ConvoPlus\Service\Prune;

use HappyBoard\Misc\AutoPrune\Audit;
use HappyBoard\Misc\AutoPrune\HoldChecker;
use HappyBoard\Misc\AutoPrune\Lock;
use HappyBoard\Misc\AutoPrune\Policy;
use XF\Service\AbstractService;

class Selector extends AbstractService
{
    public function run(bool $preview = false): array
    {
        if ((new Policy($this->app))->isDisabled())
        {
            return ['status' => 'disabled', 'candidates' => [], 'held' => [], 'pruned' => 0, 'would_trip' => false];
        }
        return (new Lock($this->db()))->run('hbConvoPrune', function () use ($preview) {
            return $this->runLocked($preview);
        }) ?? ['status' => 'busy', 'candidates' => [], 'held' => [], 'pruned' => 0, 'would_trip' => false];
    }

    protected function runLocked(bool $preview): array
    {
        $repo = $this->repository('HappyBoard\ConvoPlus:Prune');
        $config = $repo->getConfig();
        $result = ['status' => $preview ? 'preview' : $config['mode'], 'candidates' => [], 'held' => [], 'pruned' => 0, 'would_trip' => false];
        if (!$preview)
        {
            $repo->refreshHolds($config['mode'] === 'off' || (bool)$config['tripped_date']);
        }
        if (!$preview && ($config['mode'] === 'off' || $config['tripped_date']))
        {
            $result['status'] = $config['tripped_date'] ? 'tripped' : 'off';
            return $result;
        }
        if (!$preview)
        {
            $repo->extendGrace();
        }

        $audit = new Audit($this->app);
        $checker = new HoldChecker($this->app);
        $cutoff = \XF::$time - $config['inactivity_days'] * 86400;
        $dayCount = $audit->countPruned('conversation', \XF::$time - 86400);
        $lastId = 0;
        // Keyset traversal prevents held/failing conversations at the head from stalling a run.
        do
        {
            $batch = $this->db()->fetchAll("
                SELECT c.conversation_id, c.last_message_date,
                    GREATEST(c.start_date, c.last_message_date, c.hb_prune_clock) AS clock
                FROM xf_conversation_master AS c
                LEFT JOIN xf_hb_convo_prune AS p ON (p.conversation_id = c.conversation_id)
                WHERE c.conversation_id > ? AND c.hb_pruned_date = 0
                    AND GREATEST(c.start_date, c.last_message_date, c.hb_prune_clock) < ?
                    AND (p.state IS NULL OR p.state IN ('restored', 'cancelled'))
                ORDER BY c.conversation_id LIMIT 100
            ", [$lastId, $cutoff]);
            foreach ($batch AS $candidate)
            {
                $lastId = (int)$candidate['conversation_id'];
                $hold = $checker->conversation($lastId);
                if ($hold)
                {
                    $result['held'][] = ['conversation_id' => $lastId, 'reason' => $hold];
                    if ($hold === 'guard_error')
                    {
                        continue;
                    }
                }
                $candidate['hold_reason'] = $hold ?: '';
                $result['candidates'][] = $candidate;
                if (Policy::exceedsCap(count($result['candidates']), 0, $dayCount, $config['max_per_run'], $config['max_per_day']))
                {
                    $result['would_trip'] = true;
                    // Read-only preview is intentionally bounded once the breaker would trip.
                    break 2;
                }
            }
        }
        while (count($batch) === 100);

        if ($preview)
        {
            return $result;
        }
        if ($config['mode'] === 'dry_run')
        {
            foreach ($result['candidates'] AS $candidate)
            {
                $audit->log('conversation', (int)$candidate['conversation_id'], 'dry_run', [
                    'rule' => 'inactive', 'inactivity_days' => $config['inactivity_days'],
                    'would_trip' => $result['would_trip'],
                ]);
            }
            return $result;
        }
        if ($result['would_trip'])
        {
            $repo->trip('selection_cap', count($result['candidates']));
            $result['status'] = 'tripped';
            return $result;
        }

        foreach ($result['candidates'] AS $candidate)
        {
            $id = (int)$candidate['conversation_id'];
            $committed = false;
            $this->db()->beginTransaction();
            try
            {
                $master = $this->db()->fetchRow('SELECT * FROM xf_conversation_master WHERE conversation_id = ? FOR UPDATE', $id);
                $currentConfig = $repo->getConfig();
                if ((new Policy($this->app))->isDisabled() || $currentConfig['mode'] !== 'live' || $currentConfig['tripped_date'])
                {
                    $this->db()->rollback();
                    break;
                }
                if (!$master || (int)$master['hb_pruned_date']
                    || max((int)$master['start_date'], (int)$master['last_message_date'], (int)$master['hb_prune_clock']) >= \XF::$time - $currentConfig['inactivity_days'] * 86400)
                {
                    $this->db()->rollback();
                    continue;
                }
                $hold = $checker->conversation($id);
                if ($hold === 'guard_error')
                {
                    $this->db()->rollback();
                    $result['held'][] = ['conversation_id' => $id, 'reason' => $hold];
                    continue;
                }
                if (Policy::exceedsCap(1, $result['pruned'], $dayCount + $result['pruned'], $currentConfig['max_per_run'], $currentConfig['max_per_day']))
                {
                    $this->db()->rollback();
                    $repo->trip('selection_cap', 1);
                    $result['status'] = 'tripped';
                    break;
                }
                $users = $repo->recipientIds($id);
                $conversation = $this->em()->find('XF:ConversationMaster', $id);
                $conversation->fastUpdate('hb_pruned_date', \XF::$time);
                $this->db()->insert('xf_hb_convo_prune', [
                    'conversation_id' => $id, 'pruned_date' => \XF::$time,
                    'purge_after' => \XF::$time + $currentConfig['grace_days'] * 86400,
                    'state' => $hold ? 'held' : 'pending', 'hold_reason' => $hold ?: '', 'resolved_date' => 0,
                    'resolved_user_id' => 0, 'purge_attempts' => 0,
                ], false, 'pruned_date = VALUES(pruned_date), purge_after = VALUES(purge_after), state = VALUES(state), hold_reason = VALUES(hold_reason), resolved_date = 0, resolved_user_id = 0, purge_attempts = 0');
                $repo->rebuildUnread($users);
                $audit->log('conversation', $id, 'prune', ['rule' => 'inactive', 'inactivity_days' => $currentConfig['inactivity_days']]);
                if ($hold)
                {
                    $audit->log('conversation', $id, 'hold', ['reason' => $hold]);
                }
                $this->db()->commit();
                $committed = true;
                $result['pruned']++;
                $this->app->search()->delete('conversation', $id);
                $messageIds = $this->db()->fetchAllColumn('SELECT message_id FROM xf_conversation_message WHERE conversation_id = ?', $id);
                if ($messageIds)
                {
                    $this->app->search()->delete('conversation_message', $messageIds);
                }
            }
            catch (\Throwable $e)
            {
                if (!$committed)
                {
                    $this->db()->rollback();
                }
                \XF::logException($e, false, 'Conversation auto-prune selection failed (' . $id . '): ');
            }
        }
        return $result;
    }
}
