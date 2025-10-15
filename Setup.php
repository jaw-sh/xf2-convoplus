<?php

namespace HappyBoard\ConvoPlus;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Alter;

class Setup extends AbstractSetup
{
    public function install(array $stepParams = [])
    {
        $this->schemaManager()->alterTable('xf_conversation_message', function (Alter $table) {
            $table->addColumn('hb_system_message', 'tinyint')->length(3)->unsigned()->setDefault(0);
        });

        $this->schemaManager()->alterTable('xf_conversation_recipient', function (Alter $table) {
            $table->addColumn('hb_invited_by', 'int')->length(10)->unsigned()->nullable()->setDefault(null);
            $table->addColumn('hb_invited_on', 'int')->length(10)->unsigned()->nullable()->setDefault(null);
            $table->addColumn('hb_kicked_by', 'int')->length(10)->unsigned()->nullable()->setDefault(null);
            $table->addColumn('hb_kicked_on', 'int')->length(10)->unsigned()->nullable()->setDefault(null);
        });

        $this->schemaManager()->alterTable('xf_conversation_user', function (Alter $table) {
            $table->addColumn('hb_sleeping', 'tinyint')->setDefault(0);
        });
    }

    public function upgrade(array $stepParams = [])
    {
        if ($this->addOn->version_id < 2) {
            $this->schemaManager()->createTable('xf_conversation_message', function (Create $table) {
                $table->addColumn('hb_system_message', 'tinyint')->setDefault(0);
            });

            $this->schemaManager()->alterTable('xf_conversation_user', function (Alter $table) {
                $table->addColumn('hb_sleeping', 'tinyint')->setDefault(0);
            });
        }

    }

    public function uninstall(array $stepParams = [])
    {
        $this->schemaManager()->alterTable('xf_conversation_message', function (Alter $table) {
            $table->dropColumns([
                'hb_system_message',
            ]);
        });

        $this->schemaManager()->alterTable('xf_conversation_recipient', function (Alter $table) {
            $table->dropColumns([
                'hb_invited_by',
                'hb_invited_on',
                'hb_kicked_by',
                'hb_kicked_on',
            ]);
        });

        $this->schemaManager()->alterTable('xf_conversation_user', function (Alter $table) {
            $table->dropColumns(['hb_sleeping']);
        });
    }
}
