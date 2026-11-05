<?php

namespace HappyBoard\ConvoPlus\XF\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class ConversationMaster extends XFCP_ConversationMaster
{
    /**
     * When the conversation disappears for its participants (auto-pruned and
     * unreadable). The later purge date is staff-only; showing it here made
     * DMs look deleted "early" (see docs/AUTO_PRUNE.md). 0 when exempt.
     */
    public function getLifespan()
    {
        if ($this->isHbPruneExempt())
        {
            return 0;
        }
        $config = $this->repository('HappyBoard\\ConvoPlus:Prune')->getConfig();
        return max($this->start_date, $this->last_message_date, $this->hb_prune_clock)
            + $config['inactivity_days'] * 86400;
    }

    public function isHbPruneExempt(): bool
    {
        return \HappyBoard\ConvoPlus\Repository\Prune::isExemptValue($this->hb_prune_exempt_until);
    }

    public function canHbPruneExempt(): bool
    {
        $visitor = \XF::visitor();
        return $visitor->user_id && $visitor->hasPermission('conversation', 'hbPruneExempt');
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
        $structure->columns['hb_prune_exempt_until'] = ['type' => Entity::UINT, 'default' => 0];

        return $structure;
    }
}
