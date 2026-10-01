<?php

namespace HappyBoard\ConvoPlus\Tests\Integration;

use HappyBoard\ConvoPlus\Admin\Controller\Prune;
use HappyBoard\GuestPosts\Tests\Support\IntegrationTestCase;
use XF\Http\Request;
use XF\InputFilterer;
use XF\Mvc\Controller;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Exception as ReplyException;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;
use HappyBoard\Misc\AutoPrune\HoldChecker;
use PHPUnit\Framework\Attributes\DataProvider;

/** Actual XF extension chains, finders, controllers and compiled ACP templates. */
final class ConversationRuntimeTest extends IntegrationTestCase
{
    private function request(array $input = [], string $method = 'GET'): Request
    {
        return new Request(new InputFilterer(), $input, [], [], [
            'REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php',
        ]);
    }

    private function controller(string $base, array $input = [], string $method = 'GET'): Controller
    {
        $class = \XF::extendClass($base);
        return new $class(\XF::app(), $this->request($input, $method));
    }

    private function invoke(object $object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    private function assertDenied(callable $action, int $status): AbstractReply
    {
        try
        {
            $reply = $action();
        }
        catch (ReplyException $e)
        {
            $reply = $e->getReply();
        }
        self::assertInstanceOf(AbstractReply::class, $reply);
        self::assertSame($status, $reply->getResponseCode());
        return $reply;
    }

    private function visit(int $userId): \XF\Entity\User
    {
        \XF::em()->clearEntityCache();
        $user = \XF::em()->find('XF:User', $userId);
        self::assertNotNull($user);
        \XF::setVisitor($user);
        return $user;
    }

    private function makeAdmin(int $userId, array $permissions, bool $super = false): void
    {
        $this->fixture->insert('xf_admin', [
            'user_id' => $userId, 'permission_cache' => json_encode($permissions),
            'is_super_admin' => $super ? 1 : 0, 'extra_user_group_ids' => '[]',
        ]);
        \XF::db()->update('xf_user', ['is_admin' => 1], 'user_id = ?', $userId);
    }

    private function makeModerator(int $userId): void
    {
        $combination = $this->fixture->insert('xf_permission_combination', [
            'user_id' => $userId, 'user_group_list' => '2', 'cache_value' => json_encode(['general' => ['warn' => true]]),
        ]);
        \XF::db()->update('xf_user', ['is_moderator' => 1, 'permission_combination_id' => $combination], 'user_id = ?', $userId);
    }

    public function testPendingConversationReturns410ForParticipantPermissionHolderAndSuperAdmin(): void
    {
        $participants = [
            $this->fixture->user('Participant'),
            $this->fixture->user('PruneAdmin'),
            $this->fixture->user('SuperAdmin'),
        ];
        $this->makeAdmin($participants[1], ['hbConvoPrune' => true]);
        $this->makeAdmin($participants[2], [], true);
        $conversation = $this->fixture->conversation($participants, ['conversation_open' => 1, 'open_invite' => 1]);
        $this->fixture->pendingConversation($conversation);

        foreach ($participants as $userId)
        {
            $this->visit($userId);
            $master = \XF::em()->find('XF:ConversationMaster', $conversation['conversation_id']);
            self::assertFalse($master->canView($error));
            self::assertFalse($master->canReply());
            self::assertFalse($master->canInvite());
            $controller = $this->controller('XF\\Pub\\Controller\\ConversationController');
            $reply = $this->assertDenied(fn() => $this->invoke($controller, 'assertViewableUserConversation', [
                $conversation['conversation_id'],
            ]), 410);
            $errors = implode(' ', array_map('strval', $reply->getErrors()));
            self::assertStringContainsString((string)$conversation['conversation_id'], $errors);
            self::assertStringContainsString('Contact staff', $errors);
        }
    }

    public function testPendingConversationsLeaveListsAndPopupAndUnreadRebuildRestoresCounts(): void
    {
        $users = [$this->fixture->user('InboxA'), $this->fixture->user('InboxB')];
        $live = $this->fixture->conversation($users);
        $pending = $this->fixture->conversation($users);
        $this->fixture->pendingConversation($pending);
        $repo = \XF::repository('HappyBoard\\ConvoPlus:Prune');
        $repo->rebuildUnread($users);

        foreach ($users as $userId)
        {
            $user = $this->visit($userId);
            $conversations = \XF::repository('XF:Conversation')->findUserConversations($user)->fetch();
            $ids = array_map(static fn($item) => (int)$item->conversation_id, $conversations->toArray());
            self::assertContains($live['conversation_id'], $ids);
            self::assertNotContains($pending['conversation_id'], $ids);
            self::assertSame(1, (int)$user->conversations_unread);
            $popup = \XF::repository('XF:Conversation')->getUserConversationsForPopup($user, 10, 0);
            $unreadIds = array_map(static fn($item) => (int)$item->conversation_id, $popup['unread']);
            $readIds = array_map(static fn($item) => (int)$item->conversation_id, $popup['read']);
            self::assertContains($live['conversation_id'], $unreadIds);
            self::assertNotContains($pending['conversation_id'], $unreadIds);
            self::assertNotContains($pending['conversation_id'], $readIds);
        }

        self::assertTrue($repo->restore($pending['conversation_id'], $users[0]));
        self::assertSame('restored', $repo->findRecord($pending['conversation_id'])['state']);
        foreach ($users as $userId)
        {
            $user = $this->visit($userId);
            self::assertSame(2, (int)$user->conversations_unread);
            $conversations = \XF::repository('XF:Conversation')->findUserConversations($user)->fetch();
            self::assertContains($pending['conversation_id'], array_map(
                static fn($item) => (int)$item->conversation_id, $conversations->toArray()
            ));
        }
        $master = \XF::em()->find('XF:ConversationMaster', $pending['conversation_id']);
        self::assertSame(0, (int)$master->hb_pruned_date);
        self::assertSame(\XF::$time, (int)$master->hb_prune_clock);
    }

    public function testRepliesAndInvitesCannotReadOrChangePendingConversation(): void
    {
        $users = [$this->fixture->user('ReplyA'), $this->fixture->user('ReplyB')];
        $conversation = $this->fixture->conversation($users, ['conversation_open' => 1, 'open_invite' => 1]);
        $this->fixture->pendingConversation($conversation);
        $this->visit($users[0]);
        $controller = $this->controller('XF\\Pub\\Controller\\ConversationController', ['message' => 'MUST NOT BE SAVED'], 'POST');
        $params = new ParameterBag(['conversation_id' => $conversation['conversation_id']]);
        $this->assertDenied(fn() => $controller->actionAddReply($params), 410);
        $this->assertDenied(fn() => $controller->actionInvite($params), 410);
        $this->assertDenied(fn() => $controller->actionRejoin($params), 410);
        self::assertSame(1, (int)\XF::db()->fetchOne(
            'SELECT COUNT(*) FROM xf_conversation_message WHERE conversation_id = ?', $conversation['conversation_id']
        ));
        self::assertSame(2, (int)\XF::db()->fetchOne(
            'SELECT COUNT(*) FROM xf_conversation_recipient WHERE conversation_id = ?', $conversation['conversation_id']
        ));
    }

    public function testSuperApiPermissionBypassCannotReadConversationMessageOrAttachment(): void
    {
        $users = [$this->fixture->user('ApiA'), $this->fixture->user('ApiB')];
        $conversation = $this->fixture->conversation($users);
        $attachment = $this->fixture->attachment('conversation_message', $conversation['message_id']);
        $this->fixture->pendingConversation($conversation);
        $this->visit($users[0]);
        $previousKey = \XF::apiKey();
        $previousBypass = \XF::isApiBypassingPermissions();
        $key = \XF::em()->create('XF:ApiKey');
        $key->is_super_user = true;
        \XF::setApiKey($key);
        \XF::setApiBypassPermissions(true);
        try
        {
            self::assertTrue(\XF::isApiBypassingPermissions());
            $conversationController = $this->controller('XF\\Api\\Controller\\ConversationController');
            $this->assertDenied(fn() => $conversationController->actionGet(new ParameterBag([
                'conversation_id' => $conversation['conversation_id'],
            ])), 410);
            $messageController = $this->controller('XF\\Api\\Controller\\ConversationMessageController');
            $this->assertDenied(fn() => $messageController->actionGet(new ParameterBag([
                'message_id' => $conversation['message_id'],
            ])), 410);
            $attachmentController = $this->controller('XF\\Api\\Controller\\AttachmentController');
            $this->assertDenied(fn() => $attachmentController->actionGet(new ParameterBag([
                'attachment_id' => $attachment['attachment_id'],
            ])), 410);
            $this->assertDenied(fn() => $attachmentController->actionGetData(new ParameterBag([
                'attachment_id' => $attachment['attachment_id'],
            ])), 410);
        }
        finally
        {
            \XF::setApiBypassPermissions($previousBypass);
            \XF::setApiKey($previousKey);
        }
        $public = $this->controller('XF\\Pub\\Controller\\AttachmentController');
        $this->assertDenied(fn() => $public->actionIndex(new ParameterBag([
            'attachment_id' => $attachment['attachment_id'],
        ])), 410);
    }

    public function testEveryAcpActionDeniesAdminWithoutPrunePermission(): void
    {
        $user = $this->fixture->user('NodeAdmin');
        $this->makeAdmin($user, ['node' => true, 'option' => true]);
        $this->visit($user);
        self::assertTrue(\XF::visitor()->hasAdminPermission('node'));
        self::assertFalse(\XF::visitor()->hasAdminPermission('hbConvoPrune'));
        $controller = $this->controller(Prune::class);
        foreach (['Index', 'Save', 'Queue', 'Restore', 'ReleaseHold', 'ResetBreaker', 'Preview', 'Log'] as $action)
        {
            $this->assertDenied(fn() => $this->invoke($controller, 'preDispatchController', [
                $action, new ParameterBag(),
            ]), 403);
        }
    }

    public function testAcpRestoreRendersOnlyMetadataAndRestoresWithoutDisclosingContent(): void
    {
        $users = [$this->fixture->user('PrivateAuthor'), $this->fixture->user('PrivateRecipient')];
        $admin = $this->fixture->user('RestoreAdmin');
        $this->makeAdmin($admin, ['hbConvoPrune' => true]);
        $conversation = $this->fixture->conversation($users);
        $this->fixture->pendingConversation($conversation);
        $secrets = [
            (string)\XF::db()->fetchOne('SELECT title FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']),
            (string)\XF::db()->fetchOne('SELECT message FROM xf_conversation_message WHERE message_id = ?', $conversation['message_id']),
        ];
        foreach ($users as $userId)
        {
            $secrets[] = (string)\XF::db()->fetchOne('SELECT username FROM xf_user WHERE user_id = ?', $userId);
        }
        $this->visit($admin);
        $controller = $this->controller(Prune::class, ['conversation_id' => $conversation['conversation_id']]);
        $this->invoke($controller, 'preDispatchController', ['Restore', new ParameterBag()]);
        $reply = $controller->actionRestore();
        self::assertInstanceOf(View::class, $reply);
        $metadata = $reply->getParam('metadata');
        self::assertSame($conversation['conversation_id'], (int)$metadata['conversation_id']);
        self::assertSame(2, (int)$metadata['recipient_count']);
        self::assertSame('pending', $metadata['state']);
        self::assertSame([], array_diff(array_keys($metadata), [
            'conversation_id', 'start_date', 'last_message_date', 'recipient_count',
            'state', 'hold_reason', 'pruned_date', 'purge_after',
        ]));
        $html = \XF::app()->templater()->renderTemplate('admin:' . $reply->getTemplateName(), $reply->getParams());
        self::assertStringContainsString((string)$conversation['conversation_id'], $html);
        foreach ($secrets as $secret)
        {
            self::assertNotSame('', $secret);
            self::assertStringNotContainsString($secret, json_encode($reply->getParams()));
            self::assertStringNotContainsString($secret, $html);
        }
        $post = $this->controller(Prune::class, ['conversation_id' => $conversation['conversation_id']], 'POST');
        $this->invoke($post, 'preDispatchController', ['Restore', new ParameterBag()]);
        self::assertInstanceOf(Redirect::class, $post->actionRestore());
        self::assertSame('restored', \XF::repository('HappyBoard\\ConvoPlus:Prune')->findRecord($conversation['conversation_id'])['state']);
        self::assertSame(0, (int)\XF::db()->fetchOne(
            'SELECT hb_pruned_date FROM xf_conversation_master WHERE conversation_id = ?', $conversation['conversation_id']
        ));
    }

    public function testPendingContentIsAbsentFromSearchAutocompleteAlertsAndReportSnapshots(): void
    {
        $users = [$this->fixture->user('SearchA'), $this->fixture->user('SearchB')];
        $conversation = $this->fixture->conversation($users);
        $reportId = $this->fixture->report('conversation_message', $conversation['message_id'], 'resolved');
        \XF::db()->update('xf_report', ['content_info' => json_encode([
            'conversation_id' => $conversation['conversation_id'],
            'conversation_title' => 'PRIVATE REPORTED TITLE', 'message' => 'PRIVATE REPORTED MESSAGE',
            'user_id' => $users[0], 'username' => 'PRIVATE REPORTED AUTHOR',
        ])], 'report_id = ?', $reportId);
        $alertId = $this->fixture->insert('xf_user_alert', [
            'alerted_user_id' => $users[1], 'user_id' => $users[0], 'username' => 'PrivateAuthor',
            'content_type' => 'conversation_message', 'content_id' => $conversation['message_id'],
            'action' => 'mention', 'event_date' => \XF::$time, 'extra_data' => '[]',
        ]);
        $this->fixture->pendingConversation($conversation);
        $this->visit($users[1]);
        $master = \XF::em()->find('XF:ConversationMaster', $conversation['conversation_id']);
        $message = \XF::em()->find('XF:ConversationMessage', $conversation['message_id']);
        $search = \XF::app()->search();
        self::assertNull($search->handler('conversation')->getIndexData($master));
        self::assertNull($search->handler('conversation')->getAutoCompleteResult($master));
        self::assertNull($search->handler('conversation_message')->getIndexData($message));
        $alert = \XF::em()->find('XF:UserAlert', $alertId);
        self::assertFalse($alert->canView());
        self::assertSame('', $alert->render());
        self::assertNull($alert->getHandler()->getApiOutput($alert));
        $report = \XF::em()->find('XF:Report', $reportId);
        self::assertFalse($report->canView());
        self::assertSame('', $report->getContentMessage());
        self::assertStringNotContainsString('PRIVATE REPORTED TITLE', (string)$report->getTitle());
    }

    public function testPendingReportCanBeResolvedThroughMetadataWithoutExposingSnapshotsOrParticipants(): void
    {
        $users = [$this->fixture->user('ReportAuthor'), $this->fixture->user('Reporter')];
        $moderator = $this->fixture->user('ReportResolver');
        $this->makeModerator($moderator);
        $conversation = $this->fixture->conversation($users);
        $reportId = $this->fixture->report('conversation_message', $conversation['message_id']);
        $author = (string)\XF::db()->fetchOne('SELECT username FROM xf_user WHERE user_id = ?', $users[0]);
        $reporter = (string)\XF::db()->fetchOne('SELECT username FROM xf_user WHERE user_id = ?', $users[1]);
        \XF::db()->update('xf_report', [
            'content_user_id' => $users[0], 'report_count' => 1,
            'content_info' => json_encode([
                'conversation_id' => $conversation['conversation_id'], 'conversation_title' => 'PRIVATE REPORT TITLE',
                'message' => 'PRIVATE REPORT BODY', 'user_id' => $users[0], 'username' => $author,
            ]),
        ], 'report_id = ?', $reportId);
        $commentId = $this->fixture->insert('xf_report_comment', [
            'report_id' => $reportId, 'user_id' => $users[1], 'username' => $reporter,
            'comment_date' => \XF::$time - 10, 'message' => 'PRIVATE HISTORY QUOTE', 'is_report' => 1,
        ]);
        $this->fixture->pendingConversation($conversation, ['state' => 'held', 'hold_reason' => 'report']);
        $this->visit($moderator);
        self::assertTrue(\XF::visitor()->hasPermission('general', 'warn'));
        $controller = $this->controller('XF\\Pub\\Controller\\ReportController');
        $params = new ParameterBag(['report_id' => $reportId]);
        $this->invoke($controller, 'preDispatchController', ['View', $params]);
        $reply = $controller->actionView($params);
        self::assertInstanceOf(View::class, $reply);
        self::assertSame('hb_convo_pruned_report', $reply->getTemplateName());
        self::assertNull($reply->getParam('report'), 'The metadata view must not receive the private snapshot entity.');
        self::assertSame($reportId, (int)$reply->getParam('metadata')['report_id']);
        self::assertSame($commentId, (int)$reply->getParam('history')[0]['report_comment_id']);
        $html = \XF::app()->templater()->renderTemplate('public:' . $reply->getTemplateName(), $reply->getParams());
        self::assertStringContainsString('report_state', $html, 'Keep the resolution controls available.');
        foreach (['PRIVATE REPORT TITLE', 'PRIVATE REPORT BODY', 'PRIVATE HISTORY QUOTE', $author, $reporter] as $secret)
        {
            self::assertStringNotContainsString($secret, $html);
            self::assertStringNotContainsString($secret, json_encode($reply->getParams()));
        }
        $report = \XF::em()->find('XF:Report', $reportId);
        $list = \XF::app()->templater()->renderMacro('public:report_list_macros', 'item', ['report' => $report], []);
        foreach ([$author, $reporter, 'PRIVATE REPORT TITLE', 'PRIVATE REPORT BODY'] as $secret)
        {
            self::assertStringNotContainsString($secret, $list);
        }
        self::assertNotNull((new HoldChecker(\XF::app()))->conversation($conversation['conversation_id']));
        $post = $this->controller('XF\\Pub\\Controller\\ReportController', [
            'report_state' => 'resolved', 'message' => 'MUST NOT BE SAVED', 'send_alert' => 1,
        ], 'POST');
        self::assertInstanceOf(Redirect::class, $post->actionUpdate($params));
        self::assertSame('resolved', \XF::db()->fetchOne('SELECT report_state FROM xf_report WHERE report_id = ?', $reportId));
        self::assertSame('', \XF::db()->fetchOne('SELECT message FROM xf_report_comment WHERE report_id = ? ORDER BY report_comment_id DESC LIMIT 1', $reportId));
        self::assertNull((new HoldChecker(\XF::app()))->conversation($conversation['conversation_id']));
        \XF::repository('HappyBoard\\ConvoPlus:Prune')->refreshHolds();
        self::assertSame('pending', \XF::repository('HappyBoard\\ConvoPlus:Prune')->findRecord($conversation['conversation_id'])['state']);
        self::assertSame('PRIVATE HISTORY QUOTE', \XF::db()->fetchOne('SELECT message FROM xf_report_comment WHERE report_comment_id = ?', $commentId));
    }

    #[DataProvider('sensitiveHoldReasons')]
    public function testPrunedReportShowsAGenericPreservationHoldWithoutLeakingItsReason(string $reason): void
    {
        $users = [$this->fixture->user('HeldAuthor'), $this->fixture->user('HeldRecipient')];
        $moderator = $this->fixture->user('HeldReportResolver');
        $this->makeModerator($moderator);
        $conversation = $this->fixture->conversation($users);
        $reportId = $this->fixture->report('conversation_message', $conversation['message_id']);
        \XF::db()->update('xf_report', ['content_info' => json_encode([
            'conversation_id' => $conversation['conversation_id'],
            'conversation_title' => 'PRIVATE HELD TITLE', 'message' => 'PRIVATE HELD BODY',
        ])], 'report_id = ?', $reportId);
        $this->fixture->pendingConversation($conversation, ['state' => 'held', 'hold_reason' => $reason]);
        $this->visit($moderator);
        $controller = $this->controller('XF\\Pub\\Controller\\ReportController');
        $reply = $controller->actionView(new ParameterBag(['report_id' => $reportId]));
        self::assertInstanceOf(View::class, $reply);
        $metadata = $reply->getParam('metadata');
        self::assertTrue($metadata['has_preservation_hold']);
        self::assertArrayNotHasKey('hold_reason', $metadata);
        self::assertFalse($reply->getParam('canRestore'));
        $html = \XF::app()->templater()->renderTemplate('public:' . $reply->getTemplateName(), $reply->getParams());
        self::assertStringContainsString('Preservation hold', $html);
        foreach ([$reason, 'PRIVATE HELD TITLE', 'PRIVATE HELD BODY'] as $secret)
        {
            self::assertStringNotContainsString($secret, $html);
            self::assertStringNotContainsString($secret, json_encode($reply->getParams()));
        }
        self::assertSame($reason, \XF::repository('HappyBoard\\ConvoPlus:Prune')->findRecord($conversation['conversation_id'])['hold_reason'],
            'The report display must preserve the stored hold reason for the permission-gated ACP.');
    }

    public static function sensitiveHoldReasons(): array
    {
        return [['ncmec_user'], ['litigation_user']];
    }

    public function testModeratorLogListAndDetailHidePendingAndPurgedContentAndRetainStoredAudit(): void
    {
        $users = [$this->fixture->user('LogAuthor'), $this->fixture->user('LogRecipient')];
        $admin = $this->fixture->user('LogAdmin');
        $this->makeAdmin($admin, ['viewLogs' => true]);
        $conversation = $this->fixture->conversation($users);
        $author = (string)\XF::db()->fetchOne('SELECT username FROM xf_user WHERE user_id = ?', $users[0]);
        $logId = $this->fixture->insert('xf_moderator_log', [
            'user_id' => $admin, 'log_date' => \XF::$time, 'content_type' => 'conversation',
            'content_id' => $conversation['conversation_id'], 'content_user_id' => $users[0],
            'content_username' => $author, 'content_title' => 'PRIVATE LOG TITLE',
            'content_url' => 'direct-messages/PRIVATE-URL', 'discussion_content_type' => 'conversation',
            'discussion_content_id' => $conversation['conversation_id'], 'action' => 'force_join',
            'action_params' => json_encode(['report_id' => 321, 'previous_state' => 'none', 'title' => 'PRIVATE PARAM TITLE']),
        ]);
        $this->fixture->pendingConversation($conversation);
        foreach (['pending', 'purged'] as $state)
        {
            \XF::db()->update('xf_hb_convo_prune', ['state' => $state], 'conversation_id = ?', $conversation['conversation_id']);
            if ($state === 'purged')
            {
                \XF::db()->delete('xf_conversation_master', 'conversation_id = ?', $conversation['conversation_id']);
            }
            $this->visit($admin);
            $controller = $this->controller('XF\\Admin\\Controller\\LogController', ['user_id' => $admin]);
            foreach ([new ParameterBag(['moderator_log_id' => $logId]), new ParameterBag()] as $params)
            {
                $reply = $controller->actionModerator($params);
                self::assertInstanceOf(View::class, $reply);
                $html = \XF::app()->templater()->renderTemplate('admin:' . $reply->getTemplateName(), $reply->getParams());
                self::assertNotSame('', trim($html), 'Compile the core moderator log templates before running this rendering regression.');
                foreach (['PRIVATE LOG TITLE', 'PRIVATE PARAM TITLE', 'PRIVATE-URL', $author] as $secret)
                {
                    self::assertStringNotContainsString($secret, $html);
                }
                self::assertStringContainsString('321', $html, 'Retain the force-join report ID in the action audit.');
            }
            $log = \XF::em()->find('XF:ModeratorLog', $logId);
            self::assertStringNotContainsString('PRIVATE LOG TITLE', (string)$log->content_title);
            $raw = \XF::db()->fetchRow('SELECT content_title, content_username, action_params FROM xf_moderator_log WHERE moderator_log_id = ?', $logId);
            self::assertSame('PRIVATE LOG TITLE', $raw['content_title']);
            self::assertSame($author, $raw['content_username']);
            self::assertSame('PRIVATE PARAM TITLE', json_decode($raw['action_params'], true)['title']);
        }
    }

    public function testPendingAssertionsDoNotPreloadPrivateMessageRows(): void
    {
        $users = [$this->fixture->user('PreloadA'), $this->fixture->user('PreloadB')];
        $conversation = $this->fixture->conversation($users);
        $this->fixture->pendingConversation($conversation);
        $this->visit($users[0]);
        $public = $this->controller('XF\\Pub\\Controller\\ConversationController');
        $api = $this->controller('XF\\Api\\Controller\\ConversationMessageController');
        $db = \XF::db();
        $wasLogged = $db->areQueriesLogged();
        $db->logQueries(true);
        $before = count($db->getQueryLog());
        try
        {
            $this->assertDenied(fn() => $this->invoke($public, 'assertViewableUserConversation', [
                $conversation['conversation_id'], ['Master.FirstMessage.User', 'Master.LastMessage.User', 'Master.Starter'],
            ]), 410);
            $this->assertDenied(fn() => $this->invoke($api, 'assertViewableMessage', [
                $conversation['message_id'], ['Conversation.FirstMessage', 'User'],
            ]), 410);
            $queries = array_slice($db->getQueryLog(), $before);
            self::assertNotEmpty($queries);
            foreach ($queries as $query)
            {
                if (str_contains($query['query'], 'xf_conversation_message'))
                {
                    self::assertDoesNotMatchRegularExpression('/(?:SELECT|,)\s+(?:`?\w+`?\.)?\*/i', $query['query']);
                    self::assertDoesNotMatchRegularExpression('/\.(?:`?message`?)(?:\s|,|$)/i', $query['query']);
                }
            }
        }
        finally
        {
            $db->logQueries($wasLogged);
        }
    }
}
