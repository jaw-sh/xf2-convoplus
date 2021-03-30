<?php

namespace HappyBoard\ConvoPlus\Cron;

use XF;

/**
 * Cron entry for cleaning up bans.
 */
class ConversationPrune
{
    /**
     * Deletes expired bans.
     */
    public static function deleteOldConversations()
    {
        $cutOff = time() - (30 * 24 * 60 * 60);

        /** @var \XF\Finder\ConversationMaster $finder */
        $finder = XF::app()->finder('XF:ConversationMaster');
        $finder->where('last_message_date', '<', $cutOff)
            ->setDefaultOrder('last_message_date', 'desc');

        $prevTotal = 0;
        $newTotal = $finder->total();

        while ($newTotal > 0 && $prevTotal != $newTotal) {
            $prevTotal = $newTotal;

            $db = XF::db();
            $db->beginTransaction();

            foreach ($finder->fetch(10) AS $conversation) {
                $conversation->delete(false, false);
            }

            $db->commit();
            $newTotal = $finder->total();
        }
    }
}
