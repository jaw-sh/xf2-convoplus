<?php

namespace HappyBoard\ConvoPlus\XF\Pub\Controller;

use XF\Entity\ConversationRecipient;
use XF\Mvc\ParameterBag;

class ConversationController extends XFCP_ConversationController
{
	public function actionKick(ParameterBag $params)
	{
		$userConv = $this->assertViewableUserConversation($params->conversation_id);
		$conversation = $userConv->Master;

		// Get the recipient to kick
		$recipientUserId = $this->filter('user_id', 'uint');
		if (!$recipientUserId)
		{
			return $this->notFound();
		}

		/** @var ConversationRecipient $recipient */
		$recipient = $this->em()->find('XF:ConversationRecipient', [
			'conversation_id' => $conversation->conversation_id,
			'user_id' => $recipientUserId
		], ['User']);

		if (!$recipient)
		{
			return $this->notFound();
		}

		// Check if the current user can kick this recipient
		if (!$recipient->canKick($error))
		{
			return $this->noPermission($error);
		}

		if ($this->isPost())
		{
			// Perform the kick
			$visitor = \XF::visitor();
			$recipient->bulkSet([
				'recipient_state' => 'deleted_ignored',
				'hb_kicked_by' => $visitor->user_id,
				'hb_kicked_on' => \XF::$time
			]);
			$recipient->save();

            // Send notification to the kicked user
            $alertRepo = $this->repository('XF:UserAlert');
            $alertRepo->alert(
                $recipient->User,
                $visitor->user_id,
                $visitor->username,
                'user',
                $visitor->user_id,
                'conversation_kicked',
                ['conversation_title' => $conversation->title]
            );

			return $this->redirect($this->buildLink('direct-messages', $conversation));
		}
		else
		{
			// Show confirmation page
			$viewParams = [
				'conversation' => $conversation,
				'recipient' => $recipient,
				'user' => $recipient->User,

                'contentUrl' => $this->buildLink('conversations', $conversation),
                'contentTitle' => $conversation->title,
                'userName' => $recipient->User->username,
                'userUrl' => $this->buildLink('members', $recipient->User),
			];
			return $this->view('HappyBoard\ConvoPlus:Conversation\Kick', 'hb_convo_kick_confirm', $viewParams);
		}
	}

	public function actionSleep(ParameterBag $params)
	{
		$userConv = $this->assertViewableUserConversation($params->conversation_id);

		$wasSleeping = $userConv->hb_sleeping;

		$redirect = $this->getDynamicRedirect(null, false);

		if ($this->isPost())
		{
			if (!$wasSleeping)
			{
				$userConv->hb_sleeping = true;
				$message = \XF::phrase('hb_direct_message_sleeping');
			}
			else
			{
				$userConv->hb_sleeping = false;
				$message = \XF::phrase('hb_direct_message_awake');
			}

			$userConv->save();

			$reply = $this->redirect($redirect, $message);
			$reply->setJsonParam('switchKey', $userConv->hb_sleeping ? 'wake' : 'sleep');
			return $reply;
		}
		else
		{
			$viewParams = [
				'userConv' => $userConv,
				'conversation' => $userConv->Master,
				'redirect' => $redirect,
				'isSleeping' => $wasSleeping,
			];
			return $this->view('XF:Conversation\Sleep', 'hb_conversation_sleep', $viewParams);
		}
	}
}