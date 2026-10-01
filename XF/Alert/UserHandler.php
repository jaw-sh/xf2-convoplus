<?php

namespace HappyBoard\ConvoPlus\XF\Alert;

use XF\Entity\UserAlert;
use XF\Mvc\Entity\Entity;

class UserHandler extends XFCP_UserHandler
{
    public function getTemplateData($action, UserAlert $alert, ?Entity $content = null)
    {
        $data = parent::getTemplateData($action, $alert, $content);
        if ($action === 'conversation_kicked')
        {
            $id = (int)($data['extra']['conversation_id'] ?? 0);
            $live = $id && \XF::db()->fetchOne('
                SELECT conversation_id FROM xf_conversation_master
                WHERE conversation_id = ? AND hb_pruned_date = 0
            ', $id);
            // Legacy alerts had no ID, so their title snapshot cannot be verified.
            if (!$live)
            {
                $data['extra']['conversation_title'] = '';
            }
        }
        return $data;
    }
}
