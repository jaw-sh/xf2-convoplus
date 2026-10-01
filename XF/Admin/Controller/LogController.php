<?php

namespace HappyBoard\ConvoPlus\XF\Admin\Controller;

use HappyBoard\ConvoPlus\XF\PrunePrivacy;
use XF\Entity\ModeratorLog;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\View;

class LogController extends XFCP_LogController
{
    public function actionModerator(ParameterBag $params)
    {
        $reply = parent::actionModerator($params);
        if (!$reply instanceof View)
        {
            return $reply;
        }
        if ($entry = $reply->getParam('entry'))
        {
            $reply->setParam('entry', $this->pruneSafeLog($entry));
        }
        if ($entries = $reply->getParam('entries'))
        {
            $safe = [];
            foreach ($entries as $key => $entry)
            {
                $safe[$key] = $this->pruneSafeLog($entry);
            }
            $reply->setParam('entries', $safe);
        }
        return $reply;
    }

    protected function pruneSafeLog(ModeratorLog $entry)
    {
        $id = PrunePrivacy::logConversationId($entry);
        if (!PrunePrivacy::hiddenConversation($id))
        {
            return $entry;
        }
        // This is a display projection. The original row and its action audit remain intact.
        return [
            'moderator_log_id' => $entry->moderator_log_id, 'log_date' => $entry->log_date,
            'user_id' => $entry->user_id, 'User' => $entry->User, 'ip_address' => $entry->ip_address,
            'content_type' => $entry->content_type, 'content_id' => $entry->content_id,
            'discussion_content_type' => $entry->discussion_content_type,
            'discussion_content_id' => $entry->discussion_content_id,
            'content_title' => \XF::phrase('hb_convo_prune_log_content', ['id' => $id]),
            'content_username' => '', 'content_user_id' => 0,
            'content_url' => \XF::app()->router('public')->buildLink('nopath:direct-messages', ['conversation_id' => $id]),
            'action' => $entry->action, 'action_text' => PrunePrivacy::safeLogAction($entry),
            'action_params' => PrunePrivacy::safeLogParams($entry),
        ];
    }
}
