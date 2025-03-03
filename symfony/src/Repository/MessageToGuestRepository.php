<?php

namespace App\Repository;

use App\Entity\MessageToGuest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method MessageToGuest|null find($id, $lockMode = null, $lockVersion = null)
 * @method MessageToGuest|null findOneBy(array $criteria, array $orderBy = null)
 * @method MessageToGuest[]    findAll()
 * @method MessageToGuest[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MessageToGuestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageToGuest::class);
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function add(MessageToGuest $entity, bool $flush = true): void
    {
        $this->_em->persist($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    /**
     * @throws ORMException
     * @throws OptimisticLockException
     */
    public function remove(MessageToGuest $entity, bool $flush = true): void
    {
        $this->_em->remove($entity);
        if ($flush) {
            $this->_em->flush();
        }
    }

    // /**
    //  * @return MessageToGuest[] Returns an array of MessageToGuest objects
    //  */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?MessageToGuest
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
