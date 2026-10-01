<?php

namespace HappyBoard\ConvoPlus\Tests\Integration;

use HappyBoard\GuestPosts\Tests\Support\IntegrationTestCase;
use HappyBoard\Misc\AutoPrune\HoldChecker;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConversationLifecycleTest extends IntegrationTestCase
{
    public function testAnOldConversationEntersFullGraceWithoutApplyingTheThreadFloor(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()], [
            'start_date' => 1790855999, 'last_message_date' => 1790855999,
        ]);
        $result = \XF::service('HappyBoard\ConvoPlus:Prune\Selector')->run();
        self::assertGreaterThanOrEqual(1, $result['pruned']);
        $record = \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($conversation['conversation_id']);
        self::assertSame('pending', $record['state']);
        self::assertSame(\XF::$time, (int)$record['pruned_date']);
        self::assertSame(\XF::$time + 7 * 86400, (int)$record['purge_after']);
        self::assertTrue((bool) \XF::db()->fetchOne('SELECT conversation_id FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']));
        self::assertGreaterThan(\XF::$time, (int)$record['purge_after']);
    }

    public function testTheWholeTransitionTripsBeforeHidingAnyConversation(): void
    {
        $db = \XF::db();
        $pendingBefore = $db->fetchPairs('SELECT conversation_id, hb_pruned_date FROM xf_conversation_master WHERE hb_pruned_date > 0 ORDER BY conversation_id');
        $users = [$this->fixture->user(), $this->fixture->user()];
        $ids = [];
        for ($i = 0; $i < 6; $i++)
        {
            $ids[] = $this->fixture->conversation($users)['conversation_id'];
        }
        \XF::app()->registry()->set('hbConvoPruneConfig', ['max_per_run' => 5]);
        $result = \XF::service('HappyBoard\ConvoPlus:Prune\Selector')->run();
        self::assertSame('tripped', $result['status']);
        self::assertSame(0, $result['pruned']);
        self::assertSame($pendingBefore, $db->fetchPairs('SELECT conversation_id, hb_pruned_date FROM xf_conversation_master WHERE hb_pruned_date > 0 ORDER BY conversation_id'), 'A trip must not hide new conversations or change unrelated pending markers.');
        foreach ($ids as $id)
        {
            self::assertSame(0, (int)$db->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $id));
            self::assertFalse((bool)$db->fetchOne('SELECT conversation_id FROM xf_hb_convo_prune WHERE conversation_id = ?', $id), 'A tripped transition cannot stage any fixture conversation.');
        }
        $repo = \XF::repository('HappyBoard\ConvoPlus:Prune');
        self::assertGreaterThan(0, $repo->getConfig()['tripped_date']);
        $repo->resetBreaker();
        self::assertSame(0, $repo->getConfig()['tripped_date']);
    }

    public function testPreviewDryRunEmergencyStopAndGraceExtensionDoNotDelete(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $selector = \XF::service('HappyBoard\ConvoPlus:Prune\Selector');
        self::assertContains($conversation['conversation_id'], array_map('intval', array_column($selector->run(true)['candidates'], 'conversation_id')));
        self::assertSame(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']));
        \XF::app()->registry()->set('hbConvoPruneConfig', ['mode' => 'dry_run']);
        self::assertSame(0, $selector->run()['pruned']);
        $this->setDisabled(true);
        self::assertSame('disabled', $selector->run()['status']);
        $this->setDisabled(false);
        $this->fixture->pendingConversation($conversation);
        $repo = \XF::repository('HappyBoard\ConvoPlus:Prune');
        $repo->saveConfig(['grace_days' => 14, 'mode' => 'live']);
        $extended = $repo->findRecord($conversation['conversation_id']);
        self::assertSame((int)$extended['pruned_date'] + 14 * 86400, (int)$extended['purge_after']);
        $repo->saveConfig(['grace_days' => 1]);
        self::assertSame($extended['purge_after'], $repo->findRecord($conversation['conversation_id'])['purge_after']);
    }

    #[DataProvider('holds')]
    public function testRecipientsMessageAuthorsIncidentContentAndAttachmentsHoldPurge(string $case, string $expected): void
    {
        $users = [$this->fixture->user(), $this->fixture->user()];
        $conversation = $this->fixture->conversation($users);
        $this->fixture->pendingConversation($conversation);
        $incident = $this->fixture->insert('xf_usips_ncmec_incident', ['submitted_on' => null]);
        if ($case === 'recipient' || $case === 'message_author')
        {
            $user = $case === 'recipient' ? $users[1] : $this->fixture->user('FormerAuthor');
            if ($case === 'message_author')
            {
                \XF::db()->update('xf_conversation_message', ['user_id' => $user], 'message_id = ?', $conversation['message_id']);
            }
            $this->fixture->insert('xf_usips_ncmec_incident_user', ['incident_id' => $incident, 'user_id' => $user]);
        }
        elseif ($case === 'message')
        {
            $this->fixture->insert('xf_usips_ncmec_incident_content', ['incident_id' => $incident, 'content_type' => 'conversation_message', 'content_id' => $conversation['message_id']]);
        }
        elseif ($case === 'attachment' || $case === 'attachment_data' || $case === 'flagged_data')
        {
            $attachment = $this->fixture->attachment('conversation_message', $conversation['message_id'], $case === 'flagged_data' ? ['usips_ncmec_incident_count' => 1] : []);
            if ($case === 'attachment')
            {
                $this->fixture->insert('xf_usips_ncmec_incident_content', ['incident_id' => $incident, 'content_type' => 'attachment', 'content_id' => $attachment['attachment_id']]);
            }
            elseif ($case === 'attachment_data')
            {
                $this->fixture->insert('xf_usips_ncmec_incident_attachment_data', ['incident_id' => $incident, 'data_id' => $attachment['data_id']]);
            }
        }
        elseif ($case === 'litigation')
        {
            \XF::db()->update('xf_option', ['option_value' => (string)$users[1]], 'option_id = ?', 'hbLitigationUserIds');
        }
        else
        {
            $this->fixture->report('conversation_message', $conversation['message_id']);
        }
        self::assertSame($expected, (new HoldChecker())->conversation($conversation['conversation_id']));
        $this->commitFixture();
        $result = \XF::service('HappyBoard\ConvoPlus:Prune\Purger')->purge($conversation['conversation_id']);
        self::assertSame('held', $result['status']);
        self::assertSame($expected, $result['reason']);
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT conversation_id FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']));
    }

    public static function holds(): array
    {
        return [['recipient', 'ncmec_user'], ['message_author', 'ncmec_user'],
            ['message', 'ncmec_content'], ['attachment', 'ncmec_attachment'],
            ['attachment_data', 'ncmec_attachment_data'], ['flagged_data', 'ncmec_attachment_data'],
            ['litigation', 'litigation_user'], ['report', 'report']];
    }

    public function testTamperedStampCancelsAndOffModeFreezesThePendingConversation(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $this->fixture->pendingConversation($conversation);
        $this->commitFixture();
        $purger = \XF::service('HappyBoard\ConvoPlus:Prune\Purger');
        \XF::app()->registry()->set('hbConvoPruneConfig', ['mode' => 'off']);
        self::assertSame('frozen', $purger->purge($conversation['conversation_id'])['status']);
        \XF::app()->registry()->set('hbConvoPruneConfig', ['mode' => 'live']);
        \XF::db()->update('xf_conversation_master', ['hb_pruned_date' => 1], 'conversation_id = ?', $conversation['conversation_id']);
        self::assertSame('cancelled', $purger->purge($conversation['conversation_id'])['status']);
        self::assertSame('cancelled', \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($conversation['conversation_id'])['state']);
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT conversation_id FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']));
        self::assertSame(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']));
        self::assertSame(\XF::$time, (int)\XF::db()->fetchOne('SELECT hb_prune_clock FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']));
        self::assertSame(1, (int)\XF::db()->fetchOne('SELECT conversations_unread FROM xf_user WHERE user_id = ?', $conversation['users'][0]));
    }

    #[DataProvider('activityChanges')]
    public function testPurgeRechecksCurrentInactivityBeforeDeleting(string $change): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $id = $conversation['conversation_id'];
        $this->fixture->pendingConversation($conversation);
        $attachment = $this->fixture->attachment('conversation_message', $conversation['message_id'], [], true);
        $this->commitFixture();
        if ($change === 'inactivity_days')
        {
            \XF::repository('HappyBoard\ConvoPlus:Prune')->saveConfig(['inactivity_days' => 90]);
        }
        else
        {
            \XF::db()->update('xf_conversation_master', [$change => \XF::$time], 'conversation_id = ?', $id);
        }
        $purger = \XF::service('HappyBoard\ConvoPlus:Prune\Purger');
        self::assertSame('not_inactive', $purger->purge($id, true)['reason']);
        self::assertGreaterThan(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $id), 'Dry-run does not restore the pending marker.');
        $result = $purger->purge($id);
        self::assertSame(['status' => 'cancelled', 'reason' => 'not_inactive'], $result);
        self::assertSame(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $id));
        self::assertSame('cancelled', \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($id)['state']);
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT message_id FROM xf_conversation_message WHERE message_id = ?', $conversation['message_id']));
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT attachment_id FROM xf_attachment WHERE attachment_id = ?', $attachment['attachment_id']));
        foreach ($attachment['paths'] as $path)
        {
            self::assertTrue(\XF::fs()->has($path), 'Recent activity retains attachment bytes.');
        }
    }

    public static function activityChanges(): array
    {
        return [['start_date'], ['last_message_date'], ['hb_prune_clock'], ['inactivity_days']];
    }

    public function testAutomaticBatchRestoresRecentActivityAndAnOldCancelledMarker(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $this->fixture->pendingConversation($conversation);
        \XF::db()->update('xf_conversation_master', ['last_message_date' => \XF::$time], 'conversation_id = ?', $conversation['conversation_id']);
        $cancelled = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $this->fixture->pendingConversation($cancelled);
        \XF::db()->update('xf_hb_convo_prune', ['state' => 'cancelled'], 'conversation_id = ?', $cancelled['conversation_id']);
        $this->commitFixture();
        $batch = \XF::service('HappyBoard\ConvoPlus:Prune\Purger')->prepareBatch();
        self::assertSame('ready', $batch['status']);
        foreach ([$conversation, $cancelled] as $item)
        {
            self::assertNotContains($item['conversation_id'], $batch['ids']);
            self::assertSame(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $item['conversation_id']));
            self::assertSame('cancelled', \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($item['conversation_id'])['state']);
            self::assertSame(1, (int)\XF::db()->fetchOne('SELECT conversations_unread FROM xf_user WHERE user_id = ?', $item['users'][0]));
        }
    }

    #[DataProvider('agedActivityPaths')]
    public function testActivitySincePruneStillCancelsAfterItAgesPastTheInactivityCutoff(string $field, string $path, bool $sameSecond): void
    {
        $old = \XF::$time - 180 * 86400;
        $stamp = \XF::$time - 91 * 86400;
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()], [
            'start_date' => $old, 'last_message_date' => $old,
        ]);
        $id = $conversation['conversation_id'];
        $this->fixture->pendingConversation($conversation, [
            'pruned_date' => $stamp, 'purge_after' => $stamp + 90 * 86400,
        ]);
        $activity = $sameSecond ? $stamp : $stamp + 86400;
        \XF::db()->update('xf_conversation_master', ['hb_pruned_date' => $stamp, $field => $activity], 'conversation_id = ?', $id);
        $attachment = $this->fixture->attachment('conversation_message', $conversation['message_id'], [], true);
        \XF::repository('HappyBoard\ConvoPlus:Prune')->saveConfig(['grace_days' => 90]);
        $this->commitFixture();
        self::assertLessThan(\XF::$time - 30 * 86400, $activity, 'Reproduce activity which has already aged past the current inactivity cutoff.');
        $purger = \XF::service('HappyBoard\ConvoPlus:Prune\Purger');
        if ($path === 'manual')
        {
            self::assertSame(['status' => 'cancelled', 'reason' => 'activity_after_prune'], $purger->purge($id, true));
            self::assertSame($stamp, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $id));
            self::assertSame(['status' => 'cancelled', 'reason' => 'activity_after_prune'], $purger->purge($id));
        }
        elseif ($path === 'batch')
        {
            self::assertNotContains($id, $purger->prepareBatch()['ids']);
        }
        else
        {
            \XF::service('HappyBoard\ConvoPlus:Prune\Selector')->run();
        }
        self::assertSame('cancelled', \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($id)['state']);
        self::assertSame(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $id));
        self::assertSame(\XF::$time, (int)\XF::db()->fetchOne('SELECT hb_prune_clock FROM xf_conversation_master WHERE conversation_id = ?', $id));
        self::assertSame(1, (int)\XF::db()->fetchOne('SELECT conversations_unread FROM xf_user WHERE user_id = ?', $conversation['users'][0]));
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT message_id FROM xf_conversation_message WHERE message_id = ?', $conversation['message_id']));
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT attachment_id FROM xf_attachment WHERE attachment_id = ?', $attachment['attachment_id']));
        foreach ($attachment['paths'] as $file)
        {
            self::assertTrue(\XF::fs()->has($file), 'Activity recovery retains attachment bytes.');
        }
    }

    public static function agedActivityPaths(): array
    {
        return [
            ['start_date', 'manual', false], ['last_message_date', 'manual', false], ['hb_prune_clock', 'manual', false],
            ['last_message_date', 'batch', false], ['last_message_date', 'selector', false],
            ['last_message_date', 'manual', true], ['last_message_date', 'batch', true],
        ];
    }

    #[DataProvider('frozenRecoveryPaths')]
    public function testOffModeAndBreakerRepairInvalidPrunesWhileKeepingValidPendingAndHeldConversationsFrozen(string $freeze, string $path): void
    {
        $repo = \XF::repository('HappyBoard\ConvoPlus:Prune');
        $recover = [];
        foreach (['stamp', 'activity', 'cancelled'] as $change)
        {
            $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
            $this->fixture->pendingConversation($conversation);
            if ($change === 'stamp')
            {
                \XF::db()->update('xf_conversation_master', ['hb_pruned_date' => 1], 'conversation_id = ?', $conversation['conversation_id']);
            }
            elseif ($change === 'activity')
            {
                \XF::db()->update('xf_conversation_master', ['last_message_date' => \XF::$time], 'conversation_id = ?', $conversation['conversation_id']);
            }
            else
            {
                \XF::db()->update('xf_hb_convo_prune', ['state' => 'cancelled'], 'conversation_id = ?', $conversation['conversation_id']);
            }
            $recover[$change] = $conversation;
        }
        $valid = [];
        foreach (['pending', 'held'] as $state)
        {
            $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
            $this->fixture->pendingConversation($conversation, ['state' => $state, 'hold_reason' => $state === 'held' ? 'ncmec_user' : '']);
            $valid[$state] = $conversation;
        }
        $attachment = $this->fixture->attachment('conversation_message', $valid['pending']['message_id'], [], true);
        $this->commitFixture();
        \XF::app()->registry()->set('hbConvoPruneConfig', $freeze === 'off' ? ['mode' => 'off'] : ['tripped_date' => \XF::$time]);
        $purger = \XF::service('HappyBoard\ConvoPlus:Prune\Purger');
        if ($path === 'manual')
        {
            foreach (['stamp', 'activity'] as $change)
            {
                self::assertSame('cancelled', $purger->purge($recover[$change]['conversation_id'])['status']);
            }
            unset($recover['cancelled']);
            foreach ($valid as $item)
            {
                self::assertSame('frozen', $purger->purge($item['conversation_id'])['status']);
            }
        }
        elseif ($path === 'batch')
        {
            self::assertSame(['status' => 'frozen', 'ids' => []], $purger->prepareBatch());
        }
        else
        {
            self::assertSame($freeze === 'off' ? 'off' : 'tripped', \XF::service('HappyBoard\ConvoPlus:Prune\Selector')->run()['status']);
        }
        foreach ($recover as $item)
        {
            self::assertSame('cancelled', $repo->findRecord($item['conversation_id'])['state']);
            self::assertSame(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $item['conversation_id']));
            self::assertSame(1, (int)\XF::db()->fetchOne('SELECT conversations_unread FROM xf_user WHERE user_id = ?', $item['users'][0]));
            self::assertTrue((bool)\XF::db()->fetchOne('SELECT message_id FROM xf_conversation_message WHERE message_id = ?', $item['message_id']));
        }
        foreach ($valid as $state => $item)
        {
            self::assertSame($state, $repo->findRecord($item['conversation_id'])['state']);
            self::assertGreaterThan(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $item['conversation_id']));
            self::assertTrue((bool)\XF::db()->fetchOne('SELECT message_id FROM xf_conversation_message WHERE message_id = ?', $item['message_id']));
        }
        self::assertSame('ncmec_user', $repo->findRecord($valid['held']['conversation_id'])['hold_reason'], 'Frozen recovery does not release preservation holds.');
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT attachment_id FROM xf_attachment WHERE attachment_id = ?', $attachment['attachment_id']));
        foreach ($attachment['paths'] as $file)
        {
            self::assertTrue(\XF::fs()->has($file), 'Frozen processing retains attachment bytes.');
        }
    }

    public static function frozenRecoveryPaths(): array
    {
        return [['off', 'manual'], ['off', 'batch'], ['off', 'selector'],
            ['breaker', 'manual'], ['breaker', 'batch'], ['breaker', 'selector']];
    }

    #[DataProvider('disabledRecoveryGates')]
    public function testEmergencyStopAndUnavailableDependencyStillFreezeAutomaticAndManualRecovery(string $gate): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $this->fixture->pendingConversation($conversation);
        \XF::db()->update('xf_conversation_master', ['last_message_date' => \XF::$time], 'conversation_id = ?', $conversation['conversation_id']);
        $this->commitFixture();
        $dependencyActive = \XF::db()->fetchOne('SELECT active FROM xf_addon WHERE addon_id = ?', 'HappyBoard/Misc');
        try
        {
            if ($gate === 'emergency')
            {
                $this->setDisabled(true);
            }
            else
            {
                \XF::db()->update('xf_addon', ['active' => 0], 'addon_id = ?', 'HappyBoard/Misc');
            }
            $purger = \XF::service('HappyBoard\ConvoPlus:Prune\Purger');
            self::assertSame('disabled', $purger->prepareBatch()['status']);
            self::assertSame('disabled', $purger->purge($conversation['conversation_id'])['status']);
            self::assertSame('disabled', \XF::service('HappyBoard\ConvoPlus:Prune\Selector')->run()['status']);
            self::assertSame(0, \XF::repository('HappyBoard\ConvoPlus:Prune')->refreshHolds());
            self::assertGreaterThan(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']));
            self::assertSame('pending', \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($conversation['conversation_id'])['state']);
        }
        finally
        {
            if ($gate === 'dependency')
            {
                \XF::db()->update('xf_addon', ['active' => $dependencyActive], 'addon_id = ?', 'HappyBoard/Misc');
            }
        }
    }

    public static function disabledRecoveryGates(): array
    {
        return [['emergency'], ['dependency']];
    }

    public function testPurgeErrorReleaseRequiresTheOwnedStampAndEveryRealHoldToClear(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $id = $conversation['conversation_id'];
        $this->fixture->pendingConversation($conversation);
        \XF::db()->update('xf_hb_convo_prune', ['state' => 'held', 'hold_reason' => 'purge_error', 'purge_attempts' => 3], 'conversation_id = ?', $id);
        $reportId = $this->fixture->report('conversation_message', $conversation['message_id']);
        $this->commitFixture();
        $repo = \XF::repository('HappyBoard\ConvoPlus:Prune');
        self::assertFalse($repo->releaseHold($id));
        self::assertSame(3, (int)$repo->findRecord($id)['purge_attempts']);
        \XF::db()->update('xf_report', ['report_state' => 'resolved'], 'report_id = ?', $reportId);
        $repo->refreshHolds();
        self::assertSame('held', $repo->findRecord($id)['state'], 'Three failures still require an explicit release.');
        self::assertTrue($repo->releaseHold($id, $conversation['users'][0]));
        self::assertSame('pending', $repo->findRecord($id)['state']);
        self::assertSame(0, (int)$repo->findRecord($id)['purge_attempts']);
        self::assertSame($conversation['users'][0], (int)\XF::db()->fetchOne("SELECT user_id FROM xf_hb_prune_log WHERE content_type = 'conversation' AND content_id = ? AND action = 'release' ORDER BY log_id DESC LIMIT 1", $id));
        \XF::db()->update('xf_hb_convo_prune', ['state' => 'held', 'hold_reason' => 'purge_error', 'purge_attempts' => 3], 'conversation_id = ?', $id);
        \XF::db()->update('xf_conversation_master', ['hb_pruned_date' => 1], 'conversation_id = ?', $id);
        self::assertFalse($repo->releaseHold($id));
        self::assertSame('cancelled', $repo->findRecord($id)['state']);
        self::assertSame(0, (int)\XF::db()->fetchOne('SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $id));
    }

    public function testPurgeRefusesACallerTransactionAndLeavesRowsAndFilesUntouched(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $this->fixture->pendingConversation($conversation);
        $attachment = $this->fixture->attachment('conversation_message', $conversation['message_id'], [], true);
        $result = \XF::service('HappyBoard\ConvoPlus:Prune\Purger')->purge($conversation['conversation_id']);
        self::assertSame('error', $result['status']);
        self::assertSame('transaction_active', $result['reason']);
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT attachment_id FROM xf_attachment WHERE attachment_id = ?', $attachment['attachment_id']));
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT message_id FROM xf_conversation_message WHERE message_id = ?', $conversation['message_id']));
        self::assertSame('pending', \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($conversation['conversation_id'])['state']);
        foreach ($attachment['paths'] as $path)
        {
            self::assertTrue(\XF::fs()->has($path), 'Refused purge cannot remove bytes.');
        }
    }

    public function testConversationPurgeRemovesActualFilesAndAllMessageDependents(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $id = $conversation['conversation_id'];
        $messageId = $conversation['message_id'];
        $this->fixture->pendingConversation($conversation);
        $attachment = $this->fixture->attachment('conversation_message', $messageId, [], true);
        foreach (['xf_user_alert', 'xf_edit_history', 'xf_content_spam_cache', 'xf_reaction_content'] as $table)
        {
            $this->fixture->insert($table, ['content_type' => 'conversation_message', 'content_id' => $messageId]);
        }
        foreach (['mention', 'quote'] as $action)
        {
            $this->fixture->insert('xf_user_alert', ['content_type' => 'conversation_message', 'content_id' => $messageId, 'action' => $action]);
        }
        $this->fixture->insert('xf_draft', ['draft_key' => 'conversation-reply-' . $id, 'user_id' => $conversation['users'][0]]);
        $reportId = $this->fixture->report('conversation_message', $messageId, 'resolved');
        $this->commitFixture();
        $purger = \XF::service('HappyBoard\ConvoPlus:Prune\Purger');
        self::assertSame('would_purge', $purger->purge($id, true)['status']);
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT message_id FROM xf_conversation_message WHERE message_id = ?', $messageId));
        $result = $purger->purge($id);
        self::assertSame('purged', $result['status'], $this->failureSummary($result));
        foreach (['xf_conversation_master', 'xf_conversation_message', 'xf_conversation_recipient', 'xf_conversation_user'] as $table)
        {
            self::assertSame(0, (int)\XF::db()->fetchOne('SELECT COUNT(*) FROM ' . $table . ' WHERE conversation_id = ?', $id), $table);
        }
        foreach (['xf_user_alert', 'xf_edit_history', 'xf_content_spam_cache', 'xf_reaction_content', 'xf_attachment', 'xf_search_index'] as $table)
        {
            self::assertSame(0, (int)\XF::db()->fetchOne("SELECT COUNT(*) FROM " . $table . " WHERE content_type = 'conversation_message' AND content_id = ?", $messageId), $table);
        }
        self::assertSame(0, (int)\XF::db()->fetchOne('SELECT COUNT(*) FROM xf_draft WHERE draft_key = ?', 'conversation-reply-' . $id));
        self::assertFalse((bool)\XF::db()->fetchOne('SELECT data_id FROM xf_attachment_data WHERE data_id = ?', $attachment['data_id']));
        foreach ($attachment['paths'] as $path)
        {
            self::assertFalse(\XF::fs()->has($path), $path);
        }
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT report_id FROM xf_report WHERE report_id = ?', $reportId));
        self::assertSame('purged', \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($id)['state']);
    }

    public function testThreeDeleteFailuresRollBackAndHoldForReview(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $this->fixture->pendingConversation($conversation);
        $this->commitFixture();
        $purger = new class(\XF::app()) extends \HappyBoard\ConvoPlus\Service\Prune\Purger
        {
            protected function cleanupContent(int $conversationId, array $messageIds): void
            {
                parent::cleanupContent($conversationId, $messageIds);
                $this->db()->delete('xf_conversation_message', 'conversation_id = ?', $conversationId);
                throw new \RuntimeException('Injected cleanup failure');
            }
        };
        for ($i = 1; $i <= 3; $i++)
        {
            self::assertSame('error', $purger->purge($conversation['conversation_id'])['status']);
            self::assertTrue((bool)\XF::db()->fetchOne('SELECT message_id FROM xf_conversation_message WHERE message_id = ?', $conversation['message_id']), 'SQL deletion rolls back.');
            self::assertSame($i, (int)\XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($conversation['conversation_id'])['purge_attempts']);
        }
        $record = \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($conversation['conversation_id']);
        self::assertSame('held', $record['state']);
        self::assertSame('purge_error', $record['hold_reason']);
    }

    public function testFailureAfterAttachmentEntityDeletionRetainsEveryRowAndFile(): void
    {
        $conversation = $this->fixture->conversation([$this->fixture->user(), $this->fixture->user()]);
        $this->fixture->pendingConversation($conversation);
        $attachment = $this->fixture->attachment('conversation_message', $conversation['message_id'], [], true);
        $this->commitFixture();
        $purger = new class(\XF::app()) extends \HappyBoard\ConvoPlus\Service\Prune\Purger
        {
            protected function cleanupContent(int $conversationId, array $messageIds): void
            {
                parent::cleanupContent($conversationId, $messageIds);
                throw new \RuntimeException('Injected failure after attachment entity hooks');
            }
        };
        self::assertSame('error', $purger->purge($conversation['conversation_id'])['status']);
        self::assertFalse(\XF::db()->inTransaction(), 'Nested entity savepoints must all roll back.');
        self::assertSame(1, (int)\XF::db()->fetchOne('SELECT attach_count FROM xf_conversation_message WHERE message_id = ?', $conversation['message_id']));
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT attachment_id FROM xf_attachment WHERE attachment_id = ?', $attachment['attachment_id']));
        self::assertTrue((bool)\XF::db()->fetchOne('SELECT data_id FROM xf_attachment_data WHERE data_id = ?', $attachment['data_id']));
        $record = \XF::repository('HappyBoard\ConvoPlus:Prune')->findRecord($conversation['conversation_id']);
        self::assertSame('pending', $record['state']);
        self::assertSame(1, (int)$record['purge_attempts']);
        foreach ($attachment['paths'] as $path)
        {
            self::assertTrue(\XF::fs()->has($path), 'A failed content transaction must retain attachment bytes.');
        }
    }
}
