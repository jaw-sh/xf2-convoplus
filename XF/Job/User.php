<?php

namespace HappyBoard\ConvoPlus\XF\Job;

class User extends XFCP_User
{
    protected function rebuildById($id)
    {
        parent::rebuildById($id);
        // The core rebuild counts ConversationUser rows directly, including pending DMs.
        $this->app->repository('HappyBoard\\ConvoPlus:Prune')->rebuildUnread([(int)$id]);
    }
}
