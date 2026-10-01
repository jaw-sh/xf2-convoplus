<?php

namespace HappyBoard\ConvoPlus;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;

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

        $this->installPruning();
    }

    public function upgrade(array $stepParams = [])
    {
        if ($this->addOn->version_id < 2) {
            $this->schemaManager()->alterTable('xf_conversation_message', function (Alter $table) {
                $table->addColumn('hb_system_message', 'tinyint')->setDefault(0);
            });

            $this->schemaManager()->alterTable('xf_conversation_user', function (Alter $table) {
                $table->addColumn('hb_sleeping', 'tinyint')->setDefault(0);
            });
        }

        if ($this->addOn->version_id < 10)
        {
            $this->installPruning();
            $this->db()->delete('xf_cron_entry', 'entry_id = ?', 'HBConverastionPrune');
        }
    }

    public function uninstall(array $stepParams = [])
    {
        $schema = $this->schemaManager();
        if ($schema->columnExists('xf_conversation_master', 'hb_pruned_date') && $schema->tableExists('xf_hb_convo_prune'))
        {
            $result = (new \HappyBoard\Misc\AutoPrune\Lock($this->db()))->run('hbConvoPrune', function () {
                $ids = $this->db()->fetchAllColumn("SELECT p.conversation_id FROM xf_hb_convo_prune p
                    INNER JOIN xf_conversation_master c ON (c.conversation_id = p.conversation_id)
                    WHERE p.state IN ('pending', 'held') AND p.pruned_date > 0 AND p.pruned_date = c.hb_pruned_date");
                $repo = $this->app->repository('HappyBoard\ConvoPlus:Prune');
                foreach ($ids AS $id)
                {
                    if (!$repo->restore((int)$id))
                    {
                        throw new \RuntimeException('Could not restore an owned pending conversation before uninstall.');
                    }
                }
                return true;
            });
            if ($result !== true)
            {
                throw new \RuntimeException('Conversation auto-prune is busy. Retry uninstall.');
            }
        }
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

        if ($schema->columnExists('xf_conversation_master', 'hb_pruned_date'))
        {
            $schema->alterTable('xf_conversation_master', function (Alter $table) {
                $table->dropColumns(['hb_pruned_date', 'hb_prune_clock']);
            });
        }
        // Tombstones and the shared audit log survive uninstall.
    }

    protected function installPruning()
    {
        $schema = $this->schemaManager();
        $schema->alterTable('xf_conversation_master', function (Alter $table) use ($schema) {
            if (!$schema->columnExists('xf_conversation_master', 'hb_pruned_date'))
            {
                $table->addColumn('hb_pruned_date', 'int')->unsigned()->setDefault(0);
            }
            if (!$schema->columnExists('xf_conversation_master', 'hb_prune_clock'))
            {
                $table->addColumn('hb_prune_clock', 'int')->unsigned()->setDefault(0);
            }
            if (!$this->db()->fetchRow("SHOW INDEX FROM xf_conversation_master WHERE Key_name = 'hb_prune_selection'"))
            {
                $table->addKey(['hb_pruned_date', 'last_message_date'], 'hb_prune_selection');
            }
        });
        if (!$schema->tableExists('xf_hb_convo_prune'))
        {
            $schema->createTable('xf_hb_convo_prune', function (Create $table) {
            $table->addColumn('conversation_id', 'int')->unsigned();
            $table->addColumn('pruned_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('purge_after', 'int')->unsigned()->setDefault(0);
            $table->addColumn('state', 'enum')->values(['pending', 'held', 'restored', 'cancelled', 'purged'])->setDefault('pending');
            $table->addColumn('hold_reason', 'varbinary', 255)->setDefault('');
            $table->addColumn('resolved_date', 'int')->unsigned()->setDefault(0);
            $table->addColumn('resolved_user_id', 'int')->unsigned()->setDefault(0);
            $table->addColumn('purge_attempts', 'tinyint')->unsigned()->setDefault(0);
            $table->addPrimaryKey('conversation_id');
            $table->addKey(['state', 'purge_after']);
            });
        }
        if (!$this->app->registry()->get('hbConvoPruneConfig'))
        {
            $this->app->registry()->set('hbConvoPruneConfig', (new \HappyBoard\Misc\AutoPrune\Policy($this->app))->conversationConfig());
        }
        // Reinstall recreates the clock column; retained restores preserve their original immunity date.
        $this->db()->query("UPDATE xf_conversation_master c
            INNER JOIN xf_hb_convo_prune p ON (p.conversation_id = c.conversation_id)
            SET c.hb_prune_clock = GREATEST(c.hb_prune_clock, p.resolved_date)
            WHERE p.state = 'restored'");
    }
}
