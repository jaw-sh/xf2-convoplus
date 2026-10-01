<?php

namespace HappyBoard\ConvoPlus\XF\Alert;

use XF\Entity\UserAlert;
use XF\Mvc\Entity\Entity;

/**
 * @extends \XF\Alert\ConversationMessageHandler
 */
class ConversationMessageHandler extends XFCP_ConversationMessageHandler
{
	public function render(UserAlert $alert, ?Entity $content = null)
	{
		$content = $content ?: $alert->Content;
		if ($content && $content->Conversation && $content->Conversation->isAutoPruned())
		{
			return '';
		}
		return parent::render($alert, $content);
	}

	public function getApiOutput(UserAlert $alert)
	{
		$content = $alert->Content;
		if ($content && $content->Conversation && $content->Conversation->isAutoPruned())
		{
			return null;
		}
		return parent::getApiOutput($alert);
	}

	public function getOptOutActions()
	{
		$actions = parent::getOptOutActions();
		
		// Add quote to the list of actions that can be opted out
		$actions[] = 'quote';
		$actions[] = 'mention';
		
		return $actions;
	}
}
