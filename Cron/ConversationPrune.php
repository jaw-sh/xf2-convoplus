<?php

namespace HappyBoard\ConvoPlus\Cron;

use HappyBoard\Misc\AutoPrune\Policy;

class ConversationPrune
{
    public static function run()
    {
        $app = \XF::app();
        if ((new Policy($app))->isDisabled())
        {
            return;
        }

        $app->service('HappyBoard\ConvoPlus:Prune\Selector')->run();
        $app->jobManager()->enqueueUnique('hbConvoPrunePurge', 'HappyBoard\ConvoPlus:ConversationPrune', [], false);
    }
}
