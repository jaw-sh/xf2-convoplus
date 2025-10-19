<?php

namespace HappyBoard\ConvoPlus\XF\Service\Conversation;

use HappyBoard\ConvoPlus\Notifier\ConversationMessage\Mention;
use HappyBoard\ConvoPlus\Notifier\ConversationMessage\Quote;
use XF;
use XF\App;
use XF\Entity\ConversationMaster;
use XF\Entity\ConversationMessage;
use XF\Entity\User;

class NotifierService extends XFCP_NotifierService
{
	protected $quotedUserIds = [];
	protected $mentionedUserIds = [];

	/**
	 * Set the user IDs that were quoted in the conversation message
	 *
	 * @param array $userIds
	 * @return $this
	 */
	public function setQuotedUserIds(array $userIds)
	{
		$this->quotedUserIds = $userIds;
		return $this;
	}

	/**
	 * Set the user IDs that were mentioned in the conversation message
	 *
	 * @param array $userIds
	 * @return $this
	 */
	public function setMentionedUserIds(array $userIds)
	{
		$this->mentionedUserIds = $userIds;
		return $this;
	}

	public function notifyReply(ConversationMessage $message)
	{
		// First send quote notifications to quoted users
		if ($this->quotedUserIds)
		{
			$this->_sendQuoteNotifications($message, $this->quotedUserIds);
		}

		// Then send mention notifications to mentioned users
		if ($this->mentionedUserIds)
		{
			$this->_sendMentionNotifications($message, $this->mentionedUserIds);
		}

		// Finally send standard reply notifications
		return parent::notifyReply($message);
	}

	/**
	 * Send notifications to users who were quoted
	 *
	 * @param ConversationMessage $message
	 * @param array $quotedUserIds
	 * @return array
	 */
	protected function _sendQuoteNotifications(ConversationMessage $message, array $quotedUserIds)
	{
		if (!$quotedUserIds)
		{
			return [];
		}

		$notifier = \XF::app()->notifier(Quote::class, $message);
		$users = $this->_getRecipientUsers();
		$usersNotified = [];

		foreach ($quotedUserIds AS $userId)
		{
			if (!isset($users[$userId]))
			{
				continue;
			}

			$user = $users[$userId];

			if (!$this->_canUserReceiveNotification($user, $message->User))
			{
				continue;
			}

			if (!$notifier->canNotify($user))
			{
				continue;
			}

			// Check if user wants to receive this alert type
			$alertRepo = \XF::repository('XF:UserAlert');
			if (!$alertRepo->userReceivesAlert($user, $message->user_id, 'conversation_message', 'quote'))
			{
				continue;
			}

			// Send the alert
			if ($notifier->sendAlert($user))
			{
				$usersNotified[$userId] = $user;
			}
		}

		return $usersNotified;
	}

	/**
	 * Send notifications to users who were mentioned
	 *
	 * @param ConversationMessage $message
	 * @param array $mentionedUserIds
	 * @return array
	 */
	protected function _sendMentionNotifications(ConversationMessage $message, array $mentionedUserIds)
	{
		if (!$mentionedUserIds)
		{
			return [];
		}

		$notifier = \XF::app()->notifier(Mention::class, $message);
		$users = $this->_getRecipientUsers();
		$usersNotified = [];

		foreach ($mentionedUserIds AS $userId)
		{
			if (!isset($users[$userId]))
			{
				continue;
			}

			$user = $users[$userId];

			if (!$this->_canUserReceiveNotification($user, $message->User))
			{
				continue;
			}

			if (!$notifier->canNotify($user))
			{
				continue;
			}

			// Check if user wants to receive this alert type
			$alertRepo = \XF::repository('XF:UserAlert');
			if (!$alertRepo->userReceivesAlert($user, $message->user_id, 'conversation_message', 'mention'))
			{
				continue;
			}

			// Send the alert
			if ($notifier->sendAlert($user))
			{
				$usersNotified[$userId] = $user;
			}
		}

		return $usersNotified;
	}
}
