<?php

namespace HappyBoard\ConvoPlus\XF\Api\Controller;

use XF\Mvc\ParameterBag;

class AttachmentsController extends XFCP_AttachmentsController
{
    protected function preDispatchController($action, ParameterBag $params)
    {
        parent::preDispatchController($action, $params);
        $keyValue = $this->filter('key', 'str');
        $key = $keyValue ? $this->em()->find('XF:ApiAttachmentKey', $keyValue) : null;
        $type = $key ? $key->content_type : $this->filter('type', 'str');
        if ($type !== 'conversation_message')
        {
            return;
        }
        $context = $key ? $key->context : $this->filter('context', 'array-str');
        $conversation = null;
        if (!empty($context['message_id']))
        {
            $message = $this->em()->find('XF:ConversationMessage', (int)$context['message_id']);
            $conversation = $message ? $message->Conversation : null;
        }
        else if (!empty($context['conversation_id']))
        {
            $conversation = $this->em()->find('XF:ConversationMaster', (int)$context['conversation_id']);
        }
        if ($conversation && $conversation->isAutoPruned())
        {
            throw $this->exception($this->error(\XF::phrase('hb_prune_conversation_gone', [
                'id' => $conversation->conversation_id,
            ]), 410));
        }
    }
}
