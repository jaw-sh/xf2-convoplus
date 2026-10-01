<?php

namespace HappyBoard\ConvoPlus\XF\Api\Controller;

use HappyBoard\ConvoPlus\XF\PrunePrivacy;

class AttachmentController extends XFCP_AttachmentController
{
    protected function assertViewableAttachment($id, $with = 'api')
    {
        // Core reuses this row; only DM attachments need a privacy lookup before container loading.
        $attachment = $this->assertRecordExists('XF:Attachment', $id, $with);
        $conversationId = $attachment->content_type === 'conversation_message'
            ? PrunePrivacy::pendingAttachmentConversation((int)$id) : 0;
        if ($conversationId)
        {
            throw $this->exception($this->error(\XF::phrase('hb_prune_conversation_gone', [
                'id' => $conversationId,
            ]), 410));
        }
        $attachment = parent::assertViewableAttachment($id, $with);
        if ($attachment->content_type === 'conversation_message')
        {
            $message = $attachment->Container;
            if ($message && $message->Conversation && $message->Conversation->isAutoPruned())
            {
                throw $this->exception($this->error(\XF::phrase('hb_prune_conversation_gone', ['id' => $message->conversation_id]), 410));
            }
        }
        return $attachment;
    }
}
