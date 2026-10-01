<?php

namespace HappyBoard\ConvoPlus\Cli\Command;

use HappyBoard\Misc\AutoPrune\Policy;
use HappyBoard\Misc\AutoPrune\CleanupRetry;
use HappyBoard\GuestPosts\Prune\Floor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PruneStatus extends Command
{
    protected function configure()
    {
        $this->setName('xf-hb:prune-status')->setDescription('Show thread and conversation auto-prune queues and circuit breakers.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = \XF::app();
        $db = $app->db();
        $policy = new Policy($app);
        $output->writeln('Emergency stop: ' . ($policy->isDisabled() ? 'enabled' : 'disabled'));
        if (class_exists(Floor::class))
        {
            $output->writeln('Thread floor code watermarks: thread=' . Floor::THREAD_WATERMARK . ', post=' . Floor::POST_WATERMARK);
            $pinned = Floor::THREAD_WATERMARK > 0 && Floor::POST_WATERMARK > 0;
            $output->writeln('Thread floor code pin: ' . ($pinned ? 'pinned' : 'unpinned; copy measured production values before rollout'));
            try
            {
                $watermarks = (new Floor($app))->getWatermarks();
                $output->writeln('Thread floor effective watermarks: thread=' . $watermarks['thread_id'] . ', post=' . $watermarks['post_id']);
                $output->writeln('Thread floor guard: ' . ($watermarks['thread_id'] > 0 && $watermarks['post_id'] > 0 ? 'ready' : 'blocked; missing or zero watermarks'));
            }
            catch (\Throwable $e)
            {
                $output->writeln('Thread floor effective watermarks: unavailable');
                $output->writeln('Thread floor guard: blocked; ' . $e->getMessage());
            }
        }
        foreach (['thread' => 'xf_hb_thread_prune', 'conversation' => 'xf_hb_convo_prune'] AS $type => $table)
        {
            if (!$db->getSchemaManager()->tableExists($table))
            {
                $output->writeln(ucfirst($type) . ' pruning: not installed');
                continue;
            }
            $output->writeln(ucfirst($type) . ' pruning:');
            $counts = $db->fetchPairs('SELECT state, COUNT(*) FROM ' . $table . ' GROUP BY state');
            foreach (['pending', 'held', 'restored', 'cancelled', 'purged'] AS $state)
            {
                $output->writeln('  ' . $state . ': ' . ($counts[$state] ?? 0));
            }
            $next = $db->fetchOne("SELECT MIN(purge_after) FROM {$table} WHERE state = 'pending'");
            $output->writeln('  Next pending purge: ' . ($next ? gmdate('Y-m-d H:i:s', (int)$next) . ' UTC' : 'none'));
        }
        if ($db->getSchemaManager()->columnExists('xf_forum', 'hb_prune_tripped_date'))
        {
            $forums = $db->fetchAll('SELECT node_id, hb_prune_tripped_date FROM xf_forum WHERE hb_prune_tripped_date > 0 ORDER BY node_id');
            $output->writeln('Tripped forums: ' . count($forums));
            foreach ($forums AS $forum)
            {
                $output->writeln('  Node ' . $forum['node_id'] . ': ' . gmdate('Y-m-d H:i:s', $forum['hb_prune_tripped_date']) . ' UTC');
            }
            if ($db->getSchemaManager()->tableExists('xf_hb_thread_prune'))
            {
                $frozen = (int)$db->fetchOne("SELECT COUNT(*) FROM xf_hb_thread_prune p
                    INNER JOIN xf_forum f ON (f.node_id = p.node_id)
                    WHERE p.state IN ('pending', 'held') AND (f.hb_prune_mode <> 'live' OR f.hb_prune_tripped_date > 0)");
                $output->writeln('Frozen pending/held threads: ' . $frozen);
            }
        }
        $config = $policy->conversationConfig();
        $output->writeln('Conversation mode: ' . $config['mode']);
        $output->writeln('Conversation breaker: ' . ($config['tripped_date'] ? gmdate('Y-m-d H:i:s', $config['tripped_date']) . ' UTC' : 'clear'));
        $this->writeCleanupStatus($output);
        return 0;
    }

    protected function writeCleanupStatus(OutputInterface $output): void
    {
        $db = \XF::db();
        $classes = [
            'HappyBoard\\Misc:AutoPruneAttachmentCleanup', 'HappyBoard\\Misc\\Job\\AutoPruneAttachmentCleanup',
            'HappyBoard\\GuestPosts:ThreadPruneAttachmentData', 'HappyBoard\\GuestPosts\\Job\\ThreadPruneAttachmentData',
            'HappyBoard\\GuestPosts:PruneBannerCleanup', 'HappyBoard\\GuestPosts\\Job\\PruneBannerCleanup',
        ];
        $totals = ['jobs' => 0, 'items' => 0, 'failed' => 0, 'held' => 0, 'escalated' => 0, 'invalid' => 0];
        $lastId = 0;
        do
        {
            // Bound memory use: payloads can contain many durable media snapshots.
            $rows = $db->fetchAll('SELECT job_id, execute_data FROM xf_job WHERE job_id > ?
                AND execute_class IN (' . $db->quote($classes) . ') ORDER BY job_id LIMIT 100', $lastId);
            foreach ($rows AS $row)
            {
                $totals['jobs']++;
                foreach (CleanupRetry::summarizePayload((string) $row['execute_data']) AS $key => $count)
                {
                    $totals[$key] += $count;
                }
                $lastId = (int) $row['job_id'];
            }
        }
        while (count($rows) === 100);
        $output->writeln('Deferred cleanup: jobs=' . $totals['jobs'] . ', pending items=' . $totals['items']
            . ', failing=' . $totals['failed'] . ', held=' . $totals['held'] . ', escalated=' . $totals['escalated']);
        if ($totals['invalid'])
        {
            $output->writeln('Deferred cleanup invalid payloads requiring review: ' . $totals['invalid']);
        }
    }
}
