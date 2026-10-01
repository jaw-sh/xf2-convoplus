<?php

namespace HappyBoard\ConvoPlus\XF\Pub\Controller;

use XF\Mvc\ParameterBag;
use HappyBoard\ConvoPlus\XF\PrunePrivacy;

class AttachmentController extends XFCP_AttachmentController
{
    public function actionIndex(ParameterBag $params)
    {
        // Match the core's bare lookup exactly; parent reuses this entity for ordinary attachments.
        $attachment = $this->em()->find('XF:Attachment', $params->attachment_id);
        $conversationId = $attachment && $attachment->content_type === 'conversation_message'
            ? PrunePrivacy::pendingAttachmentConversation((int)$params->attachment_id) : 0;
        if ($conversationId)
        {
            return $this->error(\XF::phrase('hb_prune_conversation_gone', ['id' => $conversationId]), 410);
        }
        return parent::actionIndex($params);
    }
}
