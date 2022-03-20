<?php

namespace HappyBoard\ConvoPlus;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Alter;

class Setup extends AbstractSetup
{
    public function install(array $stepParams = [])
    {
        $this->schemaManager()->alterTable('xf_conversation_recipient', function (Alter $table) {
            $table->addColumn('hb_invited_by', 'int')->length(10)->unsigned()->nullable()->setDefault(null);
            $table->addColumn('hb_invited_on', 'int')->length(10)->unsigned()->nullable()->setDefault(null);
            $table->addColumn('hb_kicked_by', 'int')->length(10)->unsigned()->nullable()->setDefault(null);
            $table->addColumn('hb_kicked_on', 'int')->length(10)->unsigned()->nullable()->setDefault(null);
        });
    }

    public function upgrade(array $stepParams = [])
    {
        // TODO: Implement upgrade() method.
    }

    public function uninstall(array $stepParams = [])
    {
        $this->schemaManager()->alterTable('xf_conversation_recipient', function (Alter $table) {
            $table->dropColumns([
                'hb_invited_by',
                'hb_invited_on',
                'hb_kicked_by',
                'hb_kicked_on',
            ]);
        });
    }
}
