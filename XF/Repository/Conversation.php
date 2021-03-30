<?php

namespace HappyBoard\ConvoPlus\XF\Repository;

use XF;
use XF\Entity\ConversationMaster;
use XF\Entity\User;

class Conversation extends XFCP_Conversation
{
    // Directly copied from XF\Repository\Conversation
    public function insertRecipients(ConversationMaster $conversation, array $recipientUsers, User $from = null)
    {
        $existingRecipients = $conversation->Recipients->populate();
        $insertedActiveUsers = [];
        $inserted = 0;
        $fromUserId = $from ? $from->user_id : null;

        $this->db()->beginTransaction();

        /** @var \XF\Entity\User $user */
        foreach ($recipientUsers AS $user)
        {
            if ($fromUserId && $user->isIgnoring($fromUserId))
            {
                $state = 'deleted_ignored';
            }
            else
            {
                $state = 'active';
            }

            if (isset($existingRecipients[$user->user_id]))
            {
                $recipient = $existingRecipients[$user->user_id];

                if ($recipient->recipient_state != 'deleted')
                {
                    // keep the current state regardless of the new state unless in an unignored deleted state
                    continue;
                }
                else if ($state != 'active')
                {
                    // if we're in a unignored deleted state, don't allow us to go to anything other than active
                    continue;
                }
            }
            else
            {
                $recipient = $conversation->getNewRecipient($user);
            }

            if ($fromUserId && $user->user_id == $fromUserId)
            {
                // if inserting by self, that would imply they're creating a conversation, so mark it read
                $recipient->last_read_date = $conversation->last_message_date;
            }

            $recipient->recipient_state = $state;

            if ($recipient->isInsert())
            {
                $inserted++; // need to update recipient count and cache

                if ($recipient->recipient_state == 'active')
                {
                    $insertedActiveUsers[$user->user_id] = $user;
                }
            }

            // HB: Add inviter data.
            if (!is_null($from))
            {
                $recipient->hb_invited_by = $from->user_id;
                $recipient->hb_invited_on = XF::$time;
            }

            $recipient->save(true, false);
        }

        if ($inserted)
        {
            $this->rebuildConversationRecipientCache($conversation);
        }

        $this->db()->commit();

        return $insertedActiveUsers;
    }
}
