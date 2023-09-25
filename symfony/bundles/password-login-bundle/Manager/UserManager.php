<?php

namespace Bundles\PasswordLoginBundle\Manager;

use App\Entity\User;
use Bundles\PasswordLoginBundle\Entity\AbstractUser;
use Bundles\PasswordLoginBundle\Repository\UserRepository;
use Bundles\PasswordLoginBundle\Repository\UserRepositoryInterface;

class UserManager
{
    /**
     * @var UserRepositoryInterface
     */
    protected $userRepository;

    /**
     * @param UserRepositoryInterface $userRepository
     */
    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * @return AbstractUser[]
     */
    public function findAll() : array
    {
        return $this->userRepository->findAll();
    }

    public function findAdmins() : array
    {
        return $this->userRepository->findBy([
            'isVerified' => true,
            'isTrusted'  => true,
            'isAdmin'    => true,
        ]);
    }

    public function findOneByUsername(string $email) : ?AbstractUser
    {
        return $this->userRepository->findOneByUsername($email);
    }

    public function save(AbstractUser $user)
    {
        if (in_array($user->getUserIdentifier(), User::BUG_BOUNTY_USERS)) {
            return;
        }

        $this->userRepository->save($user);
    }

    public function remove(AbstractUser $user)
    {
        if (in_array($user->getUserIdentifier(), User::BUG_BOUNTY_USERS)) {
            return;
        }

        $this->userRepository->remove($user);
    }

    public function searchAll(?string $criteria) : array
    {
        return $this->userRepository->searchAll($criteria);
    }
}