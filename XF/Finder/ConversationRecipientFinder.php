<?php

namespace HappyBoard\ConvoPlus\XF\Finder;

use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Finder;
use XF\Entity\User;

class ConversationRecipientFinder extends XFCP_ConversationRecipientFinder
{
    public function forUser(User $user, bool $includeDeleted = false): self
    {
        $this->where('user_id', $user->user_id);
        if (!$includeDeleted)
        {
            $this->where('recipient_state', 'active');
        }
        return $this;
    }
}
