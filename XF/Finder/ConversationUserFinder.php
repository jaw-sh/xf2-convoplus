<?php

namespace HappyBoard\ConvoPlus\XF\Finder;

use XF\Entity\User;

class ConversationUserFinder extends XFCP_ConversationUserFinder
{
    public function forList(User $user)
    {
        parent::forList($user);
        return $this->where('Master.hb_pruned_date', 0);
    }
}
