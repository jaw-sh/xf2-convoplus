<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use HappyBoard\ConvoPlus\XF\PrunePrivacy;

class ModeratorLog extends XFCP_ModeratorLog
{
    public function isPrunedConversationLog(): bool
    {
        return PrunePrivacy::hiddenConversation(PrunePrivacy::logConversationId($this));
    }

    public function getContentTitle()
    {
        if ($this->isPrunedConversationLog())
        {
            return \XF::phrase('hb_convo_prune_log_content', ['id' => PrunePrivacy::logConversationId($this)]);
        }
        return parent::getContentTitle();
    }
}
