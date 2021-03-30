<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ConversationRecipient extends XFCP_ConversationRecipient
{
    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->columns['hb_invited_by'] = ['type' => self::UINT, 'required' => false];
        $structure->columns['hb_invited_on'] = ['type' => self::UINT, 'required' => false];
        $structure->columns['hb_kicked_by'] = ['type' => self::UINT, 'required' => false];
        $structure->columns['hb_kicked_on'] = ['type' => self::UINT, 'required' => false];

        $structure->relations['InvitedBy'] = [
            'entity' => 'XF:User',
            'type' => self::TO_ONE,
            'conditions' => [
                ['user_id', '=', '$hb_invited_by']
            ],
            'primary' => true,
        ];
        $structure->relations['KickedBy'] = [
            'entity' => 'XF:User',
            'type' => self::TO_ONE,
            'conditions' => [
                ['user_id', '=', '$hb_kicked_by']
            ],
            'primary' => true,
        ];
        return $structure;
    }
}
