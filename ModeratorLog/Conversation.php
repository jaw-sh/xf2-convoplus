<?php

namespace HappyBoard\ConvoPlus\ModeratorLog;

use XF\Entity\ModeratorLog;
use XF\ModeratorLog\AbstractHandler;
use XF\Mvc\Entity\Entity;

/**
 * Moderator log handler for direct messages (content type "conversation").
 * Used to record moderator force-joins from reports.
 */
class Conversation extends AbstractHandler
{
    protected function getLogActionForChange(Entity $content, $field, $newValue, $oldValue)
    {
        return false;
    }

    protected function setupLogEntityContent(ModeratorLog $log, Entity $content)
    {
        /** @var \XF\Entity\ConversationMaster $content */
        $log->content_user_id = $content->user_id;
        $log->content_username = $content->username;
        $log->content_title = $content->title;
        $log->content_url = \XF::app()->router('public')->buildLink('nopath:direct-messages', $content);
        $log->discussion_content_type = 'conversation';
        $log->discussion_content_id = $content->conversation_id;
    }

    public function getContentTitle(ModeratorLog $log)
    {
        return \XF::phrase('direct_message_x', [
            'title' => \XF::app()->stringFormatter()->censorText($log->content_title_),
        ]);
    }
}
