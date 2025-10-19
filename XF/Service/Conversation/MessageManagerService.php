<?php

namespace HappyBoard\ConvoPlus\XF\Service\Conversation;

use XF\Entity\User;
use XF\Repository\UserRepository;

class MessageManagerService extends XFCP_MessageManagerService
{
	protected $quotedMessages = [];
	protected $mentionedUsers = [];

	public function getQuotedMessages()
	{
		return $this->quotedMessages;
	}

	public function getQuotedUserIds()
	{
		if (!$this->quotedMessages)
		{
			return [];
		}

		$messageIds = array_keys($this->quotedMessages);
		$quotedUserIds = [];

		if (empty($messageIds))
		{
			return [];
		}

		$db = $this->db();
		$messageUserMap = $db->fetchPairs("
			SELECT message_id, user_id
			FROM xf_conversation_message
			WHERE message_id IN (" . $db->quote($messageIds) . ")
		");

		foreach ($messageUserMap AS $messageId => $userId)
		{
			if (!isset($this->quotedMessages[$messageId]) || !$userId)
			{
				continue;
			}

			$quote = $this->quotedMessages[$messageId];
			// Check if the quote has a specific member ID specified
			// If it does, only notify that user if it matches the message author
			if (!isset($quote['member']) || $quote['member'] == $userId)
			{
				$quotedUserIds[] = $userId;
			}
		}

		return array_unique($quotedUserIds);
	}

	public function getMentionedUsers($limitPermissions = true)
	{
		if ($limitPermissions && $this->conversationMessage)
		{
			/** @var User $user */
			$user = $this->conversationMessage->User ?: $this->repository(UserRepository::class)->getGuestUser();
			return $user->getAllowedUserMentions($this->mentionedUsers);
		}
		else
		{
			return $this->mentionedUsers;
		}
	}

	public function getMentionedUserIds($limitPermissions = true)
	{
		return array_keys($this->getMentionedUsers($limitPermissions));
	}

	public function setMessage($message, $format = true, $checkValidity = true)
	{
		$preparer = $this->getMessagePreparer($format);
		$this->conversationMessage->message = $preparer->prepare($message, $checkValidity);
		$this->conversationMessage->embed_metadata = $preparer->getEmbedMetadata();

		// Extract quotes - conversation quotes use 'convMessage' not 'conversation_message'
		$this->quotedMessages = [];
		$allQuotes = $preparer->getQuotes();
		
		// Process quotes to extract conversation message IDs
		foreach ($allQuotes as $quote) {
			if (isset($quote['convMessage'])) {
				$messageId = intval($quote['convMessage']);
				$this->quotedMessages[$messageId] = $quote;
			}
		}
		
		$this->mentionedUsers = $preparer->getMentionedUsers();

		return $preparer->pushEntityErrorIfInvalid($this->conversationMessage);
	}
}
