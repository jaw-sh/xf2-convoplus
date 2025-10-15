<?php

namespace HappyBoard\ConvoPlus\Cron;

use XF;

/**
 * Cron entry for cleaning up bans.
 */
class ConversationPrune
{
    public const CONVERSATION_CUTOFF = 60 * 60 * 24 * 30; // 30 days

    /**
     * Deletes expired bans.
     */
    public static function deleteOldConversations()
    {
        $cutOff = time() - self::CONVERSATION_CUTOFF;

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
