<?php

namespace HappyBoard\ConvoPlus\XF\Pub\Controller;

use XF\Entity\ConversationRecipient;
use XF\Mvc\ParameterBag;
use XF\Finder\ConversationRecipientFinder;
use XF\Repository\UserAlertRepository;

class ConversationController extends XFCP_ConversationController
{
	public function actionView(ParameterBag $params)
	{
		// Pre-validate conversation exists to avoid errors in parent
		$conversationId = intval($params->conversation_id);
		if (!$conversationId)
		{
			return $this->notFound();
		}
		
		$visitor = \XF::visitor();
		
		// Check if a conversation user record exists
		$userConv = $this->em()->find('XF:ConversationUser', [
			'conversation_id' => $conversationId,
			'owner_user_id' => $visitor->user_id
		]);
		
		// If no user conversation record, let parent handle it normally
		if ($userConv)
		{
			// Check if user was kicked and shouldn't be able to view
			if ($userConv->recipient_state === 'deleted_ignored' && 
				isset($userConv->hb_kicked_by) && $userConv->hb_kicked_by)
			{
				return $this->noPermission(\XF::phrase('hb_cannot_view_kicked_conversation'));
			}
			
			// Verify the conversation master exists
			$conversation = $userConv->Master;
			if (!$conversation || !$conversation->exists())
			{
				return $this->notFound();
			}
		}
		
		return parent::actionView($params);
	}

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

		// Hydrate the conversation relation since we already have it
		$recipient->hydrateRelation('Conversation', $conversation);

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

	public function actionRejoin(ParameterBag $params)
	{
		$visitor = \XF::visitor();
		//$conversation = $this->assertViewableConversation($params->conversation_id);

		/** @var ConversationUserFinder $finder */
		$finder = $this->finder(ConversationRecipientFinder::class);
		$finder->forUser($visitor, true);
		$finder->where('conversation_id', $params->conversation_id);
		$finder->with('Conversation');

		/** @var ConversationUser $conversation */
		$recipient = $finder->fetchOne();
		if (!$recipient || !$recipient->Conversation)
		{
			throw $this->exception($this->notFound(\XF::phrase('requested_direct_message_not_found')));
		}

		$conversation = $recipient->Conversation;

		// Check if the current user was kicked from this conversation
		if ($recipient && isset($recipient->hb_kicked_by) && $recipient->hb_kicked_by) {
			return $this->noPermission(\XF::phrase('hb_cannot_rejoin_kicked_conversation'));
		}

		// Check if user was previously a recipient
		$recipient = $this->em()->findOne('XF:ConversationRecipient', [
			'conversation_id' => $conversation->conversation_id,
			'user_id' => $visitor->user_id
		]);
		
		if (!$recipient || $recipient->recipient_state !== 'deleted_ignored') {
			return $this->noPermission();
		}
		
		// Rejoin the conversation by setting recipient state to active
		$recipient->recipient_state = 'active';
		$recipient->save();
		
		return $this->redirect($this->buildLink('conversations', $conversation));
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