<?php

namespace HappyBoard\ConvoPlus\XF\Pub\Controller;

use XF\Mvc\ParameterBag;

class ReportController extends XFCP_ReportController
{
    public function actionGoToContent(ParameterBag $params)
    {
        $visitor = \XF::visitor();
        $report = $this->assertViewableReport($params->report_id);

        // Force-join only applies to direct message reports.
        if ($report->content_type !== 'conversation_message')
        {
            return $this->redirect($this->buildLink('reports', $report));
        }

        /** @var \XF\Entity\ConversationMessage|null $content */
        $content = $report->getContent();
        $conversation = $content ? $content->Conversation : null;
        if (!$conversation)
        {
            // The reported message (or its whole DM) has been deleted or pruned.
            return $this->error(\XF::phrase('requested_direct_message_not_found'), 404);
        }

        /** @var \XF\Entity\ConversationRecipient|null $recipient */
        $recipient = $conversation->Recipients[$visitor->user_id] ?? null;

        // Already an active participant: nothing to change, just go to the message.
        if ($recipient && $recipient->recipient_state === 'active')
        {
            if ($this->request->isXhr())
            {
                // Opened as an overlay; don't render the whole DM inside it.
                return $this->message(\XF::phrase('hb_convo_already_participant_x', [
                    'url' => $content->getContentUrl(),
                ]));
            }
            return $this->redirect($content->getContentUrl());
        }

        // Only open or assigned reports may be used to join a DM.
        if (!in_array($report->report_state, ['open', 'assigned'], true))
        {
            return $this->error(\XF::phrase('hb_convo_force_join_report_not_open'));
        }

        // Joining changes membership, so require an explicit POST (CSRF-checked by core).
        if (!$this->isPost())
        {
            $viewParams = [
                'report' => $report,
                'content' => $content,
                'conversation' => $conversation,
            ];
            return $this->view('HappyBoard\ConvoPlus:Report\ForceJoin', 'hb_convo_force_join_confirm', $viewParams);
        }

        if ($recipient)
        {
            $previousState = $recipient->hb_kicked_by ? 'kicked' : $recipient->recipient_state;
            $kickedBy = (int)$recipient->hb_kicked_by;
        }
        else
        {
            $previousState = 'none';
            $kickedBy = 0;
        }

        $db = $this->app()->db();
        $db->beginTransaction();

        if ($recipient)
        {
            // Previously a participant (left, ignored or kicked) - reactivate and clear any kick.
            $recipient->recipient_state = 'active';
            $recipient->hb_kicked_by = 0;
            $recipient->hb_kicked_on = 0;
            $recipient->save(true, false);
        }
        else
        {
            /** @var \XF\Entity\ConversationRecipient $recipient */
            $recipient = $this->em()->create('XF:ConversationRecipient');
            $recipient->conversation_id = $conversation->conversation_id;
            $recipient->user_id = $visitor->user_id;
            $recipient->recipient_state = 'active';
            $recipient->last_read_date = 0;
            $recipient->hb_invited_on = \XF::$time;
            $recipient->hb_invited_by = $visitor->user_id;
            $recipient->hb_kicked_by = 0;
            $recipient->hb_kicked_on = 0;
            $recipient->save(true, false);
        }

        // Keep recipient_count and the cached recipient list (DM list/popup) accurate.
        /** @var \XF\Repository\ConversationRepository $conversationRepo */
        $conversationRepo = $this->repository('XF:Conversation');
        $conversationRepo->rebuildConversationRecipientCache($conversation);

        $this->app()->logger()->logModeratorAction('conversation', $conversation, 'force_join', [
            'report_id' => $report->report_id,
            'message_id' => $content->message_id,
            'previous_state' => $previousState,
            'kicked_by' => $kickedBy,
        ]);

        $db->commit();

        return $this->redirect($content->getContentUrl());
    }
}
