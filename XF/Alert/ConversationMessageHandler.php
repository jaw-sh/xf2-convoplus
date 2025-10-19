<?php

namespace HappyBoard\ConvoPlus\XF\Alert;

/**
 * @extends \XF\Alert\ConversationMessageHandler
 */
class ConversationMessageHandler extends XFCP_ConversationMessageHandler
{
	public function getOptOutActions()
	{
		$actions = parent::getOptOutActions();
		
		// Add quote to the list of actions that can be opted out
		$actions[] = 'quote';
		$actions[] = 'mention';
		
		return $actions;
	}
}
