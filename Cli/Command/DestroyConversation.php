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

            $isNcmecActive = \XF::isAddOnActive('USIPS/NCMEC');

            foreach ($finder->fetch(10) AS $conversation) {
                // 18 U.S. Code § 2703
                if ($isNcmecActive)
                {
                    $shouldPreserve = false;
                    foreach ($conversation->Recipients as $recipient)
                    {
                        if (\XF::repository('USIPS\NCMEC:Preservation')->isUserPreserved($recipient->user_id))
                        {
                            $shouldPreserve = true;
                            break;
                        }
                    }
                    
                    if ($shouldPreserve)
                    {
                        $output->writeln("Skipping conversation {$conversation->conversation_id} due to 18 U.S. Code § 2703 preservation hold.");
                        continue;
                    }
                }

                $conversation->delete(false, false);
            }

            $db->commit();
            $newTotal = $finder->total();
        }

        return 0;
    }
}
