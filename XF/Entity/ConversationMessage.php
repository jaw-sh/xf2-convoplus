<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ConversationMessage extends XFCP_ConversationMessage
{
    public function canEdit(&$error = null)
    {
        if ($this->Conversation && $this->Conversation->isAutoPruned())
        {
            $error = \XF::phraseDeferred('hb_prune_conversation_gone', ['id' => $this->conversation_id]);
            return false;
        }
        return parent::canEdit($error);
    }

    public function canReact(&$error = null)
    {
        if ($this->Conversation && $this->Conversation->isAutoPruned())
        {
            return false;
        }
        return parent::canReact($error);
    }

    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['hb_system_message'] = ['type' => Entity::UINT, 'default' => 0];

        return $structure;
    }
}
