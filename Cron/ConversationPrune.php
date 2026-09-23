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

            $isNcmecActive = \XF::isAddOnActive('USIPS/NCMEC');
            $preservedIds = [];

            foreach ($finder->fetch(10) AS $conversation) {
                // 18 U.S. Code § 2703
                if ($isNcmecActive)
                {
                    $shouldPreserve = false;
                    foreach ($conversation->Recipients as $recipient)
                    {
                        if (\XF::repository('USIPS\NCMEC:Preservation')->isUserPreserved($recipient->user_id))
                        {
                            $shouldPreserve = true;
                            break;
                        }
                    }
                    
                    if ($shouldPreserve)
                    {
                        $preservedIds[] = $conversation->conversation_id;
                        continue;
                    }
                }

                $conversation->delete(false, false);
            }

            $db->commit();

            // Exclude held conversations from later batches so a full batch of
            // preserved conversations can't stall pruning of everything older.
            if ($preservedIds)
            {
                $finder->where('conversation_id', '<>', $preservedIds);
            }

            $newTotal = $finder->total();
        }
    }
}
