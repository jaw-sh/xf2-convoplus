<?php

namespace HappyBoard\ConvoPlus\Job;

use HappyBoard\Misc\AutoPrune\Policy;
use XF\Job\AbstractJob;

class ConversationPrune extends AbstractJob
{
    protected $defaultData = ['ids' => null, 'done' => 0, 'purged' => 0];

    public function run($maxRunTime)
    {
        if ((new Policy($this->app))->isDisabled())
        {
            return $this->complete();
        }
        $purger = $this->app->service('HappyBoard\ConvoPlus:Prune\Purger');
        if ($this->data['ids'] === null)
        {
            $batch = $purger->prepareBatch();
            if ($batch['status'] === 'busy')
            {
                return $this->resume();
            }
            $this->data['ids'] = $batch['ids'];
        }
        $start = microtime(true);
        while ($this->data['ids'])
        {
            // One content transaction per step; every failure is excluded from this batch.
            $id = reset($this->data['ids']);
            $result = $purger->purge((int)$id, false, false, $this->data['purged']);
            if ($result['status'] === 'busy')
            {
                return $this->resume();
            }
            array_shift($this->data['ids']);
            $this->data['done']++;
            $this->data['purged'] += $result['status'] === 'purged' ? 1 : 0;
            if (in_array($result['status'], ['disabled', 'frozen'], true))
            {
                return $this->complete();
            }
            if (microtime(true) - $start >= $maxRunTime)
            {
                return $this->resume();
            }
        }
        return $this->complete();
    }

    public function getStatusMessage()
    {
        return \XF::phrase('hb_convo_prune_job_status', ['count' => $this->data['done']]);
    }

    public function canCancel()
    {
        return true;
    }

    public function canTriggerByChoice()
    {
        return false;
    }
}
