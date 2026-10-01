<?php

namespace HappyBoard\ConvoPlus\XF\Pub\Controller;

use XF\Mvc\ParameterBag;

class AttachmentController extends XFCP_AttachmentController
{
    public function actionIndex(ParameterBag $params)
    {
        $attachment = $this->em()->find('XF:Attachment', $params->attachment_id);
        if ($attachment && $attachment->content_type === 'conversation_message')
        {
            $message = $attachment->Container;
            if ($message && $message->Conversation && $message->Conversation->isAutoPruned())
            {
                return $this->error(\XF::phrase('hb_prune_conversation_gone', [
                    'id' => $message->conversation_id,
                ]), 410);
            }
        }
        return parent::actionIndex($params);
    }
}
