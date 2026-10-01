<?php

namespace HappyBoard\ConvoPlus\XF\Api\Controller;

class ConversationMessageController extends XFCP_ConversationMessageController
{
    protected function assertViewableMessage($id, $with = 'api')
    {
        // A super API key skips the core permission check; pruning is unconditional.
        $message = $this->assertRecordExists('XF:ConversationMessage', $id, $with);
        if ($message->Conversation && $message->Conversation->isAutoPruned())
        {
            throw $this->exception($this->error(\XF::phrase('hb_prune_conversation_gone', [
                'id' => $message->conversation_id,
            ]), 410));
        }
        return parent::assertViewableMessage($id, $with);
    }
}
