<?php

namespace HappyBoard\ConvoPlus\XF\ModeratorLog;

use HappyBoard\ConvoPlus\XF\PrunePrivacy;
use XF\Entity\ModeratorLog;

class Conversation extends XFCP_Conversation
{
    public function getContentTitle(ModeratorLog $log)
    {
        if (PrunePrivacy::hiddenConversation(PrunePrivacy::logConversationId($log)))
        {
            return \XF::phrase('hb_convo_prune_log_content', ['id' => PrunePrivacy::logConversationId($log)]);
        }
        return parent::getContentTitle($log);
    }

    public function getAction(ModeratorLog $log)
    {
        return PrunePrivacy::hiddenConversation(PrunePrivacy::logConversationId($log))
            ? PrunePrivacy::safeLogAction($log) : parent::getAction($log);
    }
}
