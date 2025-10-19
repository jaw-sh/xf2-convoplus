<?php

namespace HappyBoard\ConvoPlus\Notifier\ConversationMessage;

use XF\App;
use XF\Entity\ConversationMessage;
use XF\Entity\User;
use XF\Notifier\AbstractNotifier;

class Mention extends AbstractNotifier
{
	/**
	 * @var ConversationMessage
	 */
	protected $message;

	public function __construct(App $app, ConversationMessage $message)
	{
		parent::__construct($app);

		$this->message = $message;
	}

	public function canNotify(User $user)
	{
		return ($user->user_id != $this->message->user_id);
	}

	public function sendAlert(User $user)
	{
		$message = $this->message;

		return $this->basicAlert(
			$user,
			$message->user_id,
			$message->username,
			'conversation_message',
			$message->message_id,
			'mention'
		);
	}
}
