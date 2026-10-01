<?php

namespace HappyBoard\ConvoPlus\XF\Report;

use XF\Entity\Report;
use HappyBoard\ConvoPlus\XF\PrunePrivacy;

class ConversationMessageHandler extends XFCP_ConversationMessageHandler
{
    protected function isPrunedReport(Report $report): bool
    {
        return PrunePrivacy::hiddenConversation(PrunePrivacy::reportConversationId($report));
    }

    public function getContentTitle(Report $report)
    {
        return $this->isPrunedReport($report)
            ? \XF::phrase('hb_prune_conversation_content_unavailable')
            : parent::getContentTitle($report);
    }

    public function getContentMessage(Report $report)
    {
        return $this->isPrunedReport($report) ? '' : parent::getContentMessage($report);
    }
}
