<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use HappyBoard\ConvoPlus\XF\PrunePrivacy;

class Report extends XFCP_Report
{
    public function isPrunedConversationReport(): bool
    {
        return PrunePrivacy::hiddenConversation(PrunePrivacy::reportConversationId($this));
    }
}
