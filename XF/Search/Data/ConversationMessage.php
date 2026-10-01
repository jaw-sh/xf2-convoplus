<?php

namespace HappyBoard\ConvoPlus\XF\Search\Data;

use XF\Mvc\Entity\Entity;
use XF\Search\IndexRecord;

class ConversationMessage extends XFCP_ConversationMessage
{
    public function getIndexData(Entity $entity): ?IndexRecord
    {
        if ($entity->Conversation && $entity->Conversation->isAutoPruned())
        {
            return null;
        }
        return parent::getIndexData($entity);
    }
}
