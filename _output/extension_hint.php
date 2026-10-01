<?php

// ################## THIS IS A GENERATED FILE ##################
// DO NOT EDIT DIRECTLY. EDIT THE CLASS EXTENSIONS IN THE CONTROL PANEL.

/**
 * @noinspection PhpIllegalPsrClassPathInspection
 * @noinspection PhpMultipleClassesDeclarationsInOneFile
 */

namespace HappyBoard\ConvoPlus\XF\ModeratorLog
{
	class XFCP_Conversation extends \HappyBoard\ConvoPlus\ModeratorLog\Conversation {}
}

namespace HappyBoard\ConvoPlus\XF\Admin\Controller
{
	class XFCP_LogController extends \XF\Admin\Controller\LogController {}
}

namespace HappyBoard\ConvoPlus\XF\Alert
{
	class XFCP_ConversationMessageHandler extends \XF\Alert\ConversationMessageHandler {}
	class XFCP_UserHandler extends \XF\Alert\UserHandler {}
}

namespace HappyBoard\ConvoPlus\XF\Api\ControllerPlugin
{
	class XFCP_ConversationPlugin extends \XF\Api\ControllerPlugin\ConversationPlugin {}
}

namespace HappyBoard\ConvoPlus\XF\Api\Controller
{
	class XFCP_AttachmentController extends \XF\Api\Controller\AttachmentController {}
	class XFCP_AttachmentsController extends \XF\Api\Controller\AttachmentsController {}
	class XFCP_ConversationMessageController extends \XF\Api\Controller\ConversationMessageController {}
}

namespace HappyBoard\ConvoPlus\XF\Entity
{
	class XFCP_ConversationMaster extends \XF\Entity\ConversationMaster {}
	class XFCP_ConversationMessage extends \XF\Entity\ConversationMessage {}
	class XFCP_ConversationRecipient extends \XF\Entity\ConversationRecipient {}
	class XFCP_ConversationUser extends \XF\Entity\ConversationUser {}
	class XFCP_ModeratorLog extends \XF\Entity\ModeratorLog {}
	class XFCP_Report extends \XF\Entity\Report {}
}

namespace HappyBoard\ConvoPlus\XF\Finder
{
	class XFCP_ConversationRecipientFinder extends \XF\Finder\ConversationRecipientFinder {}
	class XFCP_ConversationUserFinder extends \XF\Finder\ConversationUserFinder {}
}

namespace HappyBoard\ConvoPlus\XF\Job
{
	class XFCP_User extends \XF\Job\User {}
}

namespace HappyBoard\ConvoPlus\XF\Pub\Controller
{
	class XFCP_AttachmentController extends \XF\Pub\Controller\AttachmentController {}
	class XFCP_ConversationController extends \XF\Pub\Controller\ConversationController {}
	class XFCP_ReportController extends \XF\Pub\Controller\ReportController {}
}

namespace HappyBoard\ConvoPlus\XF\Report
{
	class XFCP_ConversationMessageHandler extends \XF\Report\ConversationMessageHandler {}
}

namespace HappyBoard\ConvoPlus\XF\Repository
{
	class XFCP_Conversation extends \XF\Repository\Conversation {}
}

namespace HappyBoard\ConvoPlus\XF\Search\Data
{
	class XFCP_Conversation extends \XF\Search\Data\Conversation {}
	class XFCP_ConversationMessage extends \XF\Search\Data\ConversationMessage {}
}

namespace HappyBoard\ConvoPlus\XF\Service\Conversation
{
	class XFCP_MessageManagerService extends \XF\Service\Conversation\MessageManagerService {}
	class XFCP_NotifierService extends \XF\Service\Conversation\NotifierService {}
	class XFCP_ReplierService extends \XF\Service\Conversation\ReplierService {}
}
