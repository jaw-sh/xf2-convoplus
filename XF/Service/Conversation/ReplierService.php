<?php

namespace HappyBoard\ConvoPlus\XF\Service\Conversation;

use XF\App;
use XF\Entity\ConversationMaster;
use XF\Entity\User;

class ReplierService extends XFCP_ReplierService
{
	public function sendNotifications()
	{
		/** @var \XF\Service\Conversation\NotifierService $notifier */
		$notifier = $this->service('XF:Conversation\NotifierService', $this->conversation);
		
		// Set quoted user IDs for quote notifications
		$quotedUserIds = $this->messageManager->getQuotedUserIds();
		if ($quotedUserIds)
		{
			$notifier->setQuotedUserIds($quotedUserIds);
		}
		
		// Set mentioned user IDs for mention notifications
		$mentionedUserIds = $this->messageManager->getMentionedUserIds();
		if ($mentionedUserIds)
		{
			$notifier->setMentionedUserIds($mentionedUserIds);
		}
		
		$notifier->notifyReply($this->message);
	}
}
