<?php

namespace HappyBoard\ConvoPlus\XF\Api\ControllerPlugin;

use HappyBoard\ConvoPlus\XF\PrunePrivacy;

class ConversationPlugin extends XFCP_ConversationPlugin
{
    public function assertViewableUserConversation($id, $with = 'api')
    {
        if (PrunePrivacy::pendingUserConversation((int)$id, \XF::visitor()->user_id))
        {
            throw $this->exception($this->error(\XF::phrase('hb_prune_conversation_gone', ['id' => $id]), 410));
        }
        $conversation = parent::assertViewableUserConversation($id, $with);
        if ($conversation->Master->isAutoPruned())
        {
            throw $this->exception($this->error(\XF::phrase('hb_prune_conversation_gone', ['id' => $id]), 410));
        }
        return $conversation;
    }
}
