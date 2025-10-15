<?php

namespace HappyBoard\ConvoPlus\XF\Pub\Controller;

use XF\Mvc\ParameterBag;

class ReportController extends XFCP_ReportController
{
    public function actionGoToContent(ParameterBag $params)
    {
        $visitor = \XF::visitor();
        $report = $this->assertViewableReport($params->report_id);
        
        // Check if this is a conversation_message report
        if ($report->content_type === 'conversation_message')
        {
            $content = $report->getContent();
            if ($content && $content->Conversation)
            {
                $conversation = $content->Conversation;
                
                // Check if the visitor is already a recipient
                $recipient = $conversation->Recipients[$visitor->user_id] ?? null;
                
                if ($recipient)
                {
                    // User is already in the conversation - force set to active and clear kick/invite fields
                    $recipient->recipient_state = 'active';
                    $recipient->hb_kicked_by = 0;
                    $recipient->hb_kicked_on = 0;
                    $recipient->save();
                }
                else
                {
                    // User is not in the conversation - create new recipient record
                    $newRecipient = $this->em()->create('XF:ConversationRecipient');
                    $newRecipient->conversation_id = $conversation->conversation_id;
                    $newRecipient->user_id = $visitor->user_id;
                    $newRecipient->recipient_state = 'active';
                    $newRecipient->last_read_date = 0;
                    $newRecipient->hb_invited_on = \XF::$time;
                    $newRecipient->hb_invited_by = $visitor->user_id;
                    $newRecipient->hb_kicked_by = 0;
                    $newRecipient->hb_kicked_on = 0;
                    $newRecipient->save();
                    
                    // Update conversation recipient count
                    $conversation->recipient_count++;
                    $conversation->save();
                }
            }
        }
        
        // Get the content link and redirect
        $contentLink = $content->getContentUrl();
        if ($contentLink)
        {
            return $this->redirect($contentLink);
        }
        
        // Fallback to report view if no content link available
        return $this->redirect($this->buildLink('reports', $report));
    }
}