<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ConversationMaster extends XFCP_ConversationMaster
{
    public function getLifespan()
    {
        $config = $this->repository('HappyBoard\\ConvoPlus:Prune')->getConfig();
        return max($this->start_date, $this->last_message_date, $this->hb_prune_clock)
            + ($config['inactivity_days'] + $config['grace_days']) * 86400;
    }

    public function isAutoPruned(): bool
    {
        return $this->hb_pruned_date > 0;
    }

    public function canView(&$error = null)
    {
        if ($this->isAutoPruned())
        {
            $error = \XF::phraseDeferred('hb_prune_conversation_gone', ['id' => $this->conversation_id]);
            return false;
        }
        return parent::canView($error);
    }

    public function canReply()
    {
        return !$this->isAutoPruned() && parent::canReply();
    }

    public function canInvite()
    {
        return !$this->isAutoPruned() && parent::canInvite();
    }

    public function canEdit()
    {
        return !$this->isAutoPruned() && parent::canEdit();
    }

    public function canUploadAndManageAttachments()
    {
        return !$this->isAutoPruned() && parent::canUploadAndManageAttachments();
    }

    public function canKickRecipients()
    {
        $visitor = \XF::visitor();

        if (!$visitor->user_id)
        {
            return false;
        }

        // Only the conversation owner or staff can kick recipients
        return !$this->isAutoPruned() && ($this->user_id === $visitor->user_id || $visitor->is_staff);
    }

    public static function getStructure(Structure $structure)
    {
        $structure = parent::getStructure($structure);

        $structure->getters[] = 'lifespan';
        $structure->columns['hb_pruned_date'] = ['type' => Entity::UINT, 'default' => 0];
        $structure->columns['hb_prune_clock'] = ['type' => Entity::UINT, 'default' => 0];

        return $structure;
    }
}
