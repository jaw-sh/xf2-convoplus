<?php

namespace HappyBoard\ConvoPlus\XF;

use XF\Entity\ModeratorLog;
use XF\Entity\Report;

/** Metadata checks run before permission helpers preload private messages and authors. */
final class PrunePrivacy
{
    public static function pendingUserConversation(int $id, int $userId): bool
    {
        return (bool)\XF::db()->fetchOne('
            SELECT master.conversation_id FROM xf_conversation_master AS master
            INNER JOIN xf_conversation_user AS recipient USING (conversation_id)
            WHERE master.conversation_id = ? AND recipient.owner_user_id = ? AND master.hb_pruned_date > 0
        ', [$id, $userId]);
    }

    public static function pendingMessageConversation(int $id): int
    {
        return (int)\XF::db()->fetchOne('
            SELECT message.conversation_id FROM xf_conversation_message AS message
            INNER JOIN xf_conversation_master AS master USING (conversation_id)
            WHERE message.message_id = ? AND master.hb_pruned_date > 0
        ', $id);
    }

    public static function pendingAttachmentConversation(int $id): int
    {
        return (int)\XF::db()->fetchOne('
            SELECT message.conversation_id FROM xf_attachment AS attachment
            INNER JOIN xf_conversation_message AS message ON (message.message_id = attachment.content_id)
            INNER JOIN xf_conversation_master AS master ON (master.conversation_id = message.conversation_id)
            WHERE attachment.attachment_id = ? AND attachment.content_type = \'conversation_message\'
                AND master.hb_pruned_date > 0
        ', $id);
    }

    public static function hiddenConversation(int $id): bool
    {
        if (!$id)
        {
            return false;
        }
        return (bool)\XF::db()->fetchOne('
            SELECT conversation_id FROM xf_hb_convo_prune
            WHERE conversation_id = ? AND state IN (\'pending\', \'held\', \'purged\')
            UNION SELECT conversation_id FROM xf_conversation_master
            WHERE conversation_id = ? AND hb_pruned_date > 0
        ', [$id, $id]);
    }

    public static function reportConversationId(Report $report): int
    {
        if ($report->content_type !== 'conversation_message')
        {
            return 0;
        }
        $info = $report->content_info;
        return (int)($info['conversation_id'] ?? $info['conversation']['conversation_id']
            ?? \XF::db()->fetchOne('SELECT conversation_id FROM xf_conversation_message WHERE message_id = ?', $report->content_id));
    }

    public static function logConversationId(ModeratorLog $log): int
    {
        if ($log->content_type === 'conversation')
        {
            return (int)$log->content_id;
        }
        if ($log->discussion_content_type === 'conversation')
        {
            return (int)$log->discussion_content_id;
        }
        return 0;
    }

    public static function safeLogParams(ModeratorLog $log): array
    {
        $params = $log->action_params;
        $safe = [];
        foreach (['report_id', 'message_id', 'kicked_by'] as $key)
        {
            if (isset($params[$key]))
            {
                $safe[$key] = max(0, (int)$params[$key]);
            }
        }
        if (isset($params['previous_state']))
        {
            $safe['previous_state'] = in_array($params['previous_state'], ['none', 'active', 'deleted', 'deleted_ignored', 'kicked'], true)
                ? $params['previous_state'] : 'none';
        }
        return $safe;
    }

    public static function safeLogAction(ModeratorLog $log)
    {
        if ($log->content_type === 'conversation' && $log->action === 'force_join')
        {
            return \XF::phrase('mod_log.conversation_force_join', self::safeLogParams($log) + [
                'report_id' => 0, 'previous_state' => 'none',
            ]);
        }
        return $log->content_type . ' ' . $log->action;
    }
}
