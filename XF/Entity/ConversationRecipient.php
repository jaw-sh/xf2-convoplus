<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ConversationRecipient extends XFCP_ConversationRecipient
{
	public function canKick(&$error = null)
	{
		$visitor = \XF::visitor();

        if (!$visitor->user_id)
        {
            $error = \XF::phraseDeferred('you_must_be_logged_in');
            return false;
        }

        // Get conversation - either from relation or Master
        $conversation = $this->Conversation ?: $this->Master;
        if (!$conversation)
        {
            $error = \XF::phraseDeferred('requested_conversation_not_found');
            return false;
        }

        // Check if visitor has permission to kick in this conversation
        if (!$conversation->canKickRecipients())
        {
            $error = \XF::phraseDeferred('you_do_not_have_permission_to_kick_users');
            return false;
        }

        // Can't kick staff members
        $user = $this->User;
        if (!$user)
        {
            $error = \XF::phraseDeferred('requested_user_not_found');
            return false;
        }

        if ($user->is_staff)
        {
            $error = \XF::phraseDeferred('you_cannot_kick_staff_members');
            return false;
        }

        // Can't kick yourself
        if ($this->user_id == $visitor->user_id)
        {
            $error = \XF::phraseDeferred('you_cannot_kick_yourself');
            return false;
        }

        // Can't kick the conversation owner
        if ($conversation->user_id == $this->user_id)
        {
            $error = \XF::phraseDeferred('you_cannot_kick_the_conversation_owner');
            return false;
        }

        // Can't kick if recipient is already deleted/kicked
        if ($this->recipient_state !== 'active')
        {
            $error = \XF::phraseDeferred('this_user_is_not_an_active_participant');
            return false;
        }
        return true;
	}

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
