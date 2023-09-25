<?php

namespace Bundles\PasswordLoginBundle\Command;

use App\Entity\User;
use Bundles\PasswordLoginBundle\Manager\UserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Security\Core\Encoder\EncoderFactory;

class UserPasswordCommand extends Command
{
    /**
     * @var UserManager
     */
    private $userManager;

    /**
     * @var EncoderFactory
     */
    private $encoderFactory;

    public function __construct(UserManager $userManager, EncoderFactory $encoderFactory)
    {
        parent::__construct();

        $this->userManager    = $userManager;
        $this->encoderFactory = $encoderFactory;
    }

    protected function configure()
    {
        parent::configure();

        $this
            ->setName('user:password')
            ->setDescription('Update user\'s password')
            ->addArgument('email', InputArgument::REQUIRED, 'User\'s email')
            ->addArgument('password', InputArgument::REQUIRED, 'New password');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $username = $input->getArgument('email');
        $password = $input->getArgument('password');

        $user = $this->userManager->findOneByUsername($username);
        if (is_null($user)) {
            $output->writeln("<error>User {$username} not found.</error>");

            return 1;
        }

        $encoder = $this->encoderFactory->getEncoder(User::class);
        $user->setPassword($encoder->encodePassword($password, null));
        $this->userManager->save($user);

        $output->writeln("User <info>{$username}</info>'s password updated.");

        return 0;
    }
}
