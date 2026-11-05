<?php

namespace HappyBoard\ConvoPlus\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

/**
 * Moderator tool: exempt a direct message from auto-prune by ID.
 *
 * Moderators are usually not participants and pending DMs return 410 to
 * everyone, so this works by ID and shows metadata only (no title, names or
 * text), matching the ACP restore contract. Exempting a pending DM restores it.
 */
class PruneExempt extends AbstractController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        $this->assertRegistrationRequired();
        $visitor = \XF::visitor();
        if (!$visitor->hasPermission('conversation', 'hbPruneExempt'))
        {
            throw $this->exception($this->noPermission());
        }
    }

    protected function pruneRepo(): \HappyBoard\ConvoPlus\Repository\Prune
    {
        return $this->repository('HappyBoard\\ConvoPlus:Prune');
    }

    public function actionIndex()
    {
        $id = $this->filter('conversation_id', 'uint');
        $metadata = $id ? $this->pruneRepo()->exemptionMetadata($id) : null;
        return $this->view('HappyBoard\\ConvoPlus:PruneExempt\\Index', 'hb_convo_prune_exempt', [
            'conversationId' => $id,
            'metadata' => $metadata,
            'redirect' => $this->filter('redirect', 'str'),
        ]);
    }

    public function actionSave()
    {
        $this->assertPostOnly();
        $id = $this->filter('conversation_id', 'uint');
        $exempt = $this->filter('exempt', 'bool');
        if (!$id || !$this->pruneRepo()->exemptionMetadata($id))
        {
            return $this->error(\XF::phrase('hb_convo_prune_exempt_not_found'));
        }

        $result = $this->pruneRepo()->setExempt($id, $exempt, \XF::visitor()->user_id);
        if ($result === null)
        {
            return $this->error(\XF::phrase('hb_convo_prune_exempt_busy'));
        }

        $redirect = $this->filter('redirect', 'str');
        $target = $redirect === 'conversation'
            ? $this->buildLink('direct-messages', ['conversation_id' => $id])
            : $this->buildLink('hb-convo-exempt', null, ['conversation_id' => $id]);
        return $this->redirect($target, \XF::phrase('hb_convo_prune_exempt_updated'));
    }
}
