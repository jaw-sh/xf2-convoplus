<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ConversationUser extends XFCP_ConversationUser
{
	protected function _preSave()
    {
        if ($this->hb_sleeping)
        {
            $this->is_unread = false;
        }
    }

    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['hb_sleeping'] = ['type' => Entity::BOOL, 'default' => false];

        return $structure;
    }
}