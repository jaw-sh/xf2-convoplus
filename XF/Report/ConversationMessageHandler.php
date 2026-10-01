<?php

namespace HappyBoard\ConvoPlus\XF\Report;

use XF\Entity\Report;

class ConversationMessageHandler extends XFCP_ConversationMessageHandler
{
    protected function canViewContent(Report $report)
    {
        return !$this->isPrunedReport($report) && parent::canViewContent($report);
    }

    protected function isPrunedReport(Report $report): bool
    {
        // Report snapshots outlive their source. Read only the ID from the snapshot.
        $info = $report->content_info;
        $id = (int)($info['conversation_id'] ?? $info['conversation']['conversation_id'] ?? 0);
        if (!$id)
        {
            return false;
        }
        return (bool)\XF::db()->fetchOne('
            SELECT conversation_id FROM xf_hb_convo_prune
            WHERE conversation_id = ? AND state IN (\'pending\', \'held\', \'purged\')
        ', $id);
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
