<?php

namespace HappyBoard\ConvoPlus\XF\Search\Data;

use XF\Mvc\Entity\Entity;
use XF\Search\IndexRecord;

class Conversation extends XFCP_Conversation
{
    public function getIndexData(Entity $entity): ?IndexRecord
    {
        return $entity->isAutoPruned() ? null : parent::getIndexData($entity);
    }

    public function getAutoCompleteResult(Entity $entity, array $options = []): ?array
    {
        return $entity->isAutoPruned() ? null : parent::getAutoCompleteResult($entity, $options);
    }
}
