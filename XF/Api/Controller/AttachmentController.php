<?php

namespace HappyBoard\ConvoPlus\XF\Api\Controller;

class AttachmentController extends XFCP_AttachmentController
{
    protected function assertViewableAttachment($id, $with = 'api')
    {
        $attachment = $this->assertRecordExists('XF:Attachment', $id, $with);
        if ($attachment->content_type === 'conversation_message')
        {
            $message = $attachment->Container;
            if ($message && $message->Conversation && $message->Conversation->isAutoPruned())
            {
                throw $this->exception($this->error(\XF::phrase('hb_prune_conversation_gone', [
                    'id' => $message->conversation_id,
                ]), 410));
            }
        }
        return parent::assertViewableAttachment($id, $with);
    }
}
