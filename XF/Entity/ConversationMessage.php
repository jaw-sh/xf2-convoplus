<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ConversationMessage extends XFCP_ConversationMessage
{
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['hb_system_message'] = ['type' => Entity::UINT, 'default' => 0];

        return $structure;
    }
}