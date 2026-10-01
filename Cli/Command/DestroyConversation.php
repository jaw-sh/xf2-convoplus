<?php

namespace HappyBoard\ConvoPlus\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DestroyConversation extends Command
{
    protected function configure()
    {
        $this->setName('xf-hb:destroy-conversation')
            ->setDescription('Purge an auto-pruned conversation after its grace period, with all preservation guards.')
            ->addArgument('id', InputArgument::REQUIRED, 'Conversation ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check guards and counts without changing data.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = (int)$input->getArgument('id');
        if ($id < 1)
        {
            $output->writeln('<error>A positive conversation ID is required.</error>');
            return 1;
        }
        $result = \XF::app()->service('HappyBoard\ConvoPlus:Prune\Purger')->purge($id, (bool)$input->getOption('dry-run'), true);
        $output->writeln('Conversation ' . $id . ': ' . $result['status'] . (isset($result['reason']) ? ' (' . $result['reason'] . ')' : ''));
        if (isset($result['counts']))
        {
            $output->writeln(json_encode($result['counts'], JSON_UNESCAPED_SLASHES));
        }
        if ($result['status'] === 'not_pending')
        {
            $output->writeln('Only conversations selected by auto-prune can be purged. Review the ConvoPlus prune settings and run the normal selector to enter grace.');
        }
        else if ($result['status'] === 'not_due')
        {
            $output->writeln('The grace period expires at ' . gmdate('Y-m-d H:i:s', $result['purge_after']) . ' UTC.');
        }
        return in_array($result['status'], ['purged', 'would_purge'], true) ? 0 : 1;
    }
}
