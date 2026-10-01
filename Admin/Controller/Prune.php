<?php

namespace HappyBoard\ConvoPlus\Admin\Controller;

use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class Prune extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertAdminPermission('hbConvoPrune');
    }

    protected function pruneRepo(): \HappyBoard\ConvoPlus\Repository\Prune
    {
        return $this->repository('HappyBoard\\ConvoPlus:Prune');
    }

    public function actionIndex()
    {
        return $this->view('HappyBoard\\ConvoPlus:Prune\\Settings', 'hb_convo_prune_settings', [
            'config' => $this->pruneRepo()->getConfig(),
            'disabled' => !empty($this->app()->config('hbAutoPruneDisabled')),
        ]);
    }

    public function actionSave()
    {
        $this->assertPostOnly();
        $input = $this->filter([
            'mode' => 'str',
            'inactivity_days' => 'uint',
            'grace_days' => 'uint',
            'max_per_run' => 'uint',
            'max_per_day' => 'uint',
        ]);
        try
        {
            $this->pruneRepo()->saveConfig($input, \XF::visitor()->user_id);
        }
        catch (\InvalidArgumentException $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('hb-convo-prune'));
    }

    public function actionQueue()
    {
        $rows = $this->pruneRepo()->queue();
        $rows = array_map([$this, 'safeMetadata'], $rows);
        return $this->view('HappyBoard\\ConvoPlus:Prune\\Queue', 'hb_convo_prune_queue', ['rows' => $rows]);
    }

    public function actionRestore()
    {
        $id = $this->filter('conversation_id', 'uint');
        $metadata = $id ? $this->pruneRepo()->metadata($id) : null;
        if ($this->isPost())
        {
            if (!$metadata || !$this->pruneRepo()->restore($id, \XF::visitor()->user_id))
            {
                return $this->error(\XF::phrase('hb_convo_prune_cannot_restore'));
            }
            return $this->redirect($this->buildLink('hb-convo-prune/restore', null, ['conversation_id' => $id]));
        }
        return $this->view('HappyBoard\\ConvoPlus:Prune\\Restore', 'hb_convo_prune_restore', [
            'conversationId' => $id,
            'metadata' => $metadata ? $this->safeMetadata($metadata) : null,
        ]);
    }

    /** Keep the ACP contract explicit even if the repository gains more fields. */
    protected function safeMetadata(array $metadata): array
    {
        return array_intersect_key($metadata, array_flip([
            'conversation_id', 'start_date', 'last_message_date', 'recipient_count',
            'state', 'hold_reason', 'pruned_date', 'purge_after',
        ]));
    }

    public function actionResetBreaker()
    {
        $this->assertPostOnly();
        try
        {
            $this->pruneRepo()->resetBreaker(\XF::visitor()->user_id);
        }
        catch (\InvalidArgumentException $e)
        {
            return $this->error($e->getMessage());
        }
        return $this->redirect($this->buildLink('hb-convo-prune'));
    }

    public function actionPreview()
    {
        $result = $this->service('HappyBoard\\ConvoPlus:Prune\\Selector')->run(true);
        $result['candidates'] = count($result['candidates']);
        $result['held'] = count($result['held']);
        return $this->view('HappyBoard\\ConvoPlus:Prune\\Preview', 'hb_convo_prune_preview', [
            'result' => array_intersect_key($result, array_flip(['status', 'candidates', 'held', 'would_trip'])),
        ]);
    }

    public function actionLog()
    {
        $page = max(1, $this->filterPage());
        $db = $this->app()->db();
        $total = (int)$db->fetchOne("SELECT COUNT(*) FROM xf_hb_prune_log WHERE content_type = 'conversation'");
        $rows = $db->fetchAll($db->limit("
            SELECT log_id, content_id, action, user_id, log_date, extra
            FROM xf_hb_prune_log WHERE content_type = 'conversation'
            ORDER BY log_id DESC
        ", 100, ($page - 1) * 100));
        return $this->view('HappyBoard\\ConvoPlus:Prune\\Log', 'hb_convo_prune_log', [
            'rows' => $rows, 'page' => $page, 'total' => $total,
        ]);
    }
}
