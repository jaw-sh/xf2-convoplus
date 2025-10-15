<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use \HappyBoard\ConvoPlus\Cron\ConversationPrune;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ConversationMaster extends XFCP_ConversationMaster
{
    public function getLifespan()
    {
        return  $this->last_message_date + ConversationPrune::CONVERSATION_CUTOFF;
    }

    public function canKickRecipients()
    {
        $visitor = \XF::visitor();

        if (!$visitor->user_id)
        {
            return false;
        }

        // Only the conversation owner or staff can kick recipients
        return ($this->user_id === $visitor->user_id || $visitor->is_staff);
    }

    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

		$structure->getters[] = 'lifespan';

        return $structure;
    }
}