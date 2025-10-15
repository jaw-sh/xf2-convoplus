<?php

namespace HappyBoard\ConvoPlus\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use XF;

class DestroyConversation extends Command
{
    protected function configure()
    {
        $this
            ->setName('xf-hb:destroy-conversation')
            ->setDescription('Deletes a conversation chain.')
            ->addArgument('id', InputArgument::OPTIONAL, 'Thread ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $id = $input->getArgument('id');

        /** @var \XF\Finder\ConversationMaster $finder */
        $finder = XF::app()->finder('XF:ConversationMaster');
        $finder->where('conversation_id', '=', $id);

        $prevTotal = 0;
        $newTotal = $finder->total();

        while ($newTotal > 0 && $prevTotal != $newTotal) {
            $prevTotal = $newTotal;

            $db = XF::db();
            $db->beginTransaction();

            foreach ($finder->fetch(10) AS $conversation) {
                $conversation->delete(false, false);
            }

            $db->commit();
            $newTotal = $finder->total();
        }

        return 0;
    }
}
