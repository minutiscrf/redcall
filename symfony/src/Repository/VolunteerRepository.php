<?php

namespace App\Repository;

use App\Base\BaseRepository;
use App\Entity\Badge;
use App\Entity\Pegass;
use App\Entity\Structure;
use App\Entity\User;
use App\Entity\Volunteer;
use App\Entity\VolunteerList;
use App\Enum\Platform;
use App\Security\Helper\Security;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Volunteer|null find($id, $lockMode = null, $lockVersion = null)
 * @method Volunteer|null findOneBy(array $criteria, array $orderBy = null)
 * @method Volunteer[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class VolunteerRepository extends BaseRepository
{
    /**
     * @var Security
     */
    private $security;

    public function __construct(ManagerRegistry $registry, Security $security)
    {
        $this->security = $security;

        parent::__construct($registry, Volunteer::class);
    }

    public function findOneByExternalIdAndCurrentPlatform(string $externalId) : ?Volunteer
    {
        return $this->findOneBy([
            'platform'   => $this->security->getPlatform(),
            'externalId' => $externalId,
        ]);
    }

    public function findOneByInternalEmail(string $internalEmail)
    {
        return $this->findOneBy([
            'internalEmail' => $internalEmail,
        ]);
    }

    public function disable(Volunteer $volunteer)
    {
        if ($volunteer->isEnabled()) {
            $volunteer->setEnabled(false);
            $this->save($volunteer);
        }
    }

    public function enable(Volunteer $volunteer)
    {
        if (!$volunteer->isEnabled()) {
            $volunteer->setEnabled(true);
            $this->save($volunteer);
        }
    }

    public function lock(Volunteer $volunteer)
    {
        if (!$volunteer->isLocked()) {
            $volunteer->setLocked(true);
            $this->save($volunteer);
        }
    }

    public function unlock(Volunteer $volunteer)
    {
        if ($volunteer->isLocked()) {
            $volunteer->setLocked(false);
            $this->save($volunteer);
        }
    }

    public function findOneByExternalId(string $platform, string $externalId) : ?Volunteer
    {
        return $this->findOneBy([
            'platform'   => $platform,
            'externalId' => ltrim($externalId, '0'),
        ]);
    }

    public function searchForUser(User $user, ?string $keyword, int $maxResults, bool $onlyEnabled = false) : array
    {
        return $this->searchForUserQueryBuilder($user, $keyword, $onlyEnabled, false)
                    ->getQuery()
                    ->setMaxResults($maxResults)
                    ->getResult();
    }

    public function searchForUserQueryBuilder(User $user,
        ?string $keyword,
        bool $onlyEnabled = false,
        bool $onlyUsers = true,
        bool $onlyLocked = false) : QueryBuilder
    {
        $qb = $this->createAccessibleVolunteersQueryBuilder($user, $onlyEnabled);

        if ($keyword) {
            $this->addSearchCriteria($qb, $keyword);
        }

        if ($onlyUsers) {
            $this->addUserCriteria($qb);
        }

        if ($onlyLocked) {
            $this->addLockedCriteria($qb);
        }

        return $qb;
    }

    /**
     * @return Volunteer[]
     */
    public function searchAll(string $platform, ?string $keyword, int $maxResults, bool $enabled = false) : array
    {
        return $this->searchAllQueryBuilder($platform, $keyword, $enabled)
                    ->getQuery()
                    ->setMaxResults($maxResults)
                    ->getResult();
    }

    public function searchInStructureQueryBuilder(string $platform,
        Structure $structure,
        ?string $keyword,
        bool $onlyEnabled = true,
        bool $onlyUsers = false,
        bool $onlyLocked = false) : QueryBuilder
    {
        $qb = $this->searchAllQueryBuilder($platform, $keyword, $onlyEnabled)
                   ->join('v.structures', 's')
                   ->andWhere('s.id = :structure')
                   ->setParameter('structure', $structure);

        if ($onlyUsers) {
            $this->addUserCriteria($qb);
        }

        if ($onlyLocked) {
            $this->addLockedCriteria($qb);
        }

        return $qb;
    }

    public function searchInStructuresQueryBuilder(string $platform,
        array $structureIds,
        ?string $keyword,
        bool $onlyEnabled = true,
        bool $onlyUsers = false,
        bool $onlyLocked = false) : QueryBuilder
    {
        $qb = $this->searchAllQueryBuilder($platform, $keyword, $onlyEnabled)
                   ->join('v.structures', 's')
                   ->andWhere('s.id IN (:structures)')
                   ->setParameter('structures', $structureIds)
                   ->andWhere('s.enabled = true');

        if ($onlyUsers) {
            $this->addUserCriteria($qb);
        }

        if ($onlyLocked) {
            $this->addLockedCriteria($qb);
        }

        return $qb;
    }

    public function searchAllQueryBuilder(string $platform, ?string $keyword, bool $enabled = false) : QueryBuilder
    {
        $qb = $this->createVolunteersQueryBuilder($platform, $enabled);

        if ($keyword) {
            $this->addSearchCriteria($qb, $keyword);
        }

        return $qb;
    }

    public function searchAllWithFiltersQueryBuilder(string $platform,
        ?string $criteria,
        bool $onlyEnabled,
        bool $onlyUsers,
        bool $onlyLocked) : QueryBuilder
    {
        $qb = $this->searchAllQueryBuilder($platform, $criteria, $onlyEnabled);

        if ($onlyUsers) {
            $this->addUserCriteria($qb);
        }

        if ($onlyLocked) {
            $this->addLockedCriteria($qb);
        }

        return $qb;
    }

    public function foreach(callable $callback, ?string $filters = null)
    {
        $count = $this->createQueryBuilder('v')
                      ->select('COUNT(v.id)');

        if ($filters) {
            $count->where($filters);
        }

        $count = $count
            ->getQuery()
            ->getSingleScalarResult();

        $offset = 0;
        $stop   = false;
        while ($offset < $count) {
            $qb = $this->createQueryBuilder('v');

            if ($filters) {
                $qb->where($filters);
            }

            $qb->setFirstResult($offset)
               ->setMaxResults(1000);

            $iterator = $qb->getQuery()->toIterable();
            foreach ($iterator as $entity) {
                /* @var Volunteer $entity */

                if (false === $return = $callback($entity)) {
                    $stop = true;
                    break;
                }

                if (true === $return) {
                    continue;
                }

                $this->_em->persist($entity);
                unset($entity);
            }

            $this->_em->flush();
            $this->_em->clear();

            if ($stop) {
                break;
            }

            $offset += 1000;
        }
    }

    public function getIssues(User $user) : array
    {
        $qb = $this->createAccessibleVolunteersQueryBuilder($user);

        return $qb
            ->leftJoin('v.phones', 'p')
            ->andWhere(
                $qb->expr()->orX(
                    'v.email IS NULL or v.email = \'\'',
                    'p.id is null'
                )
            )
            ->getQuery()
            ->getResult();
    }

    public function synchronizeWithPegass()
    {
        $qb = $this->createQueryBuilder('v');

        $sub = $this->_em->createQueryBuilder()
                         ->select('TRIM(LEADING \'0\' FROM p.identifier)')
                         ->from(Pegass::class, 'p')
                         ->where('p.type = :type')
                         ->andWhere('p.enabled = :enabled');

        $qb
            ->setParameter('type', Pegass::TYPE_VOLUNTEER)
            ->setParameter('enabled', false);

        $qb
            ->update()
            ->set('v.enabled', ':enabled')
            ->where($qb->expr()->in('v.externalId', $sub->getDQL()))
            ->andWhere('v.platform = :platform')
            ->setParameter('platform', Platform::FR)
            ->getQuery()
            ->execute();
    }

    /**
     * @param array $externalIds
     *
     * @return Volunteer[]
     */
    public function getIdsByExternalIds(array $externalIds) : array
    {
        return $this->createQueryBuilder('v')
                    ->select('v.id')
                    ->andWhere('v.externalId IN (:external_ids)')
                    ->setParameter('external_ids', $externalIds, Connection::PARAM_STR_ARRAY)
                    ->getQuery()
                    ->getArrayResult();
    }

    public function filterInaccessibles(User $user, $volunteerIds) : array
    {
        $accessibles = $this->createAccessibleVolunteersQueryBuilder($user)
                            ->select('v.id')
                            ->andWhere('v.id IN (:volunteer_ids)')
                            ->setParameter('volunteer_ids', $volunteerIds)
                            ->getQuery()
                            ->getArrayResult();

        return array_diff($volunteerIds, array_column($accessibles, 'id'));
    }

    public function filterInvalidExternalIds(string $platform, array $externalIds) : array
    {
        $valid = $this->createVolunteersQueryBuilder($platform, false)
                      ->select('v.externalId')
                      ->andWhere('v.externalId IN (:external_ids)')
                      ->setParameter('external_ids', $externalIds)
                      ->getQuery()
                      ->getArrayResult();

        return array_diff(array_map('mb_strtolower', $externalIds), array_map('mb_strtolower', array_column($valid, 'externalId')));
    }

    public function getVolunteerList(string $platform, array $volunteerIds, bool $onlyEnabled = true) : array
    {
        return $this
            ->createVolunteerListQueryBuilder($platform, $volunteerIds, $onlyEnabled)
            ->getQuery()
            ->getResult();
    }

    public function getVolunteerListForUser(User $user, array $volunteerIds) : array
    {
        return $this
            ->createAccessibleVolunteersQueryBuilder($user)
            ->andWhere('v.id IN (:volunteer_ids)')
            ->setParameter('volunteer_ids', $volunteerIds)
            ->getQuery()
            ->getResult();
    }

    public function getVolunteerListInStructuresQueryBuilder(array $structureIds) : QueryBuilder
    {
        return $this
            ->createQueryBuilder('v')
            ->select('DISTINCT v.id')
            ->where('v.enabled = true')
            ->join('v.structures', 's')
            ->andWhere('s.enabled = true')
            ->andWhere('s.id IN (:structure_ids)')
            ->setParameter('structure_ids', $structureIds);
    }

    public function getVolunteerListInStructures(array $structureIds) : array
    {
        return $this
            ->getVolunteerListInStructuresQueryBuilder($structureIds)
            ->getQuery()
            ->getArrayResult();
    }

    public function getVolunteerCountInStructures(array $structureIds) : int
    {
        return $this
            ->getVolunteerListInStructuresQueryBuilder($structureIds)
            ->select('COUNT(DISTINCT v.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getVolunteerListHavingBadgesQueryBuilder(array $badgeIds) : QueryBuilder
    {
        return $this
            ->createQueryBuilder('v')
            ->select('DISTINCT v.id')
            ->where('v.enabled = true')
            ->join('v.badges', 'b')
            ->leftJoin('b.synonym', 'x')
            ->leftJoin('b.parent', 'p1')
            ->leftJoin('p1.parent', 'p2')
            ->leftJoin('p2.parent', 'p3')
            ->leftJoin('p3.parent', 'p4')
            ->andWhere('
                    b.enabled = true AND b.id IN (:badge_ids)
                 OR x.enabled = true AND x.id IN (:badge_ids)
                 OR p1.enabled = true AND p1.id IN (:badge_ids)
                 OR p2.enabled = true AND p2.id IN (:badge_ids)
                 OR p3.enabled = true AND p3.id IN (:badge_ids)
                 OR p4.enabled = true AND p4.id IN (:badge_ids)
             ')
            ->setParameter('badge_ids', $badgeIds);
    }

    public function getVolunteerListInStructuresHavingBadgesQueryBuilder(array $structureIds,
        array $badgeIds) : QueryBuilder
    {
        return $this
            ->getVolunteerListHavingBadgesQueryBuilder($badgeIds)
            ->join('v.structures', 's')
            ->andWhere('s.enabled = true')
            ->andWhere('s.id IN (:structure_ids)')
            ->setParameter('structure_ids', $structureIds);
    }

    public function getVolunteerListInStructuresHavingBadges(array $structureIds, array $badgeIds) : array
    {
        return $this->getVolunteerListInStructuresHavingBadgesQueryBuilder($structureIds, $badgeIds)
                    ->getQuery()
                    ->getArrayResult();
    }

    public function getVolunteerCountHavingBadgesQueryBuilder(array $badgeIds) : int
    {
        return $this->getVolunteerListHavingBadgesQueryBuilder($badgeIds)
                    ->select('COUNT(DISTINCT v.id)')
                    ->getQuery()
                    ->getSingleScalarResult();
    }

    public function getVolunteerCountInStructuresHavingBadges(array $structureIds, array $badgeIds) : int
    {
        return $this->getVolunteerListInStructuresHavingBadgesQueryBuilder($structureIds, $badgeIds)
                    ->select('COUNT(DISTINCT v.id)')
                    ->getQuery()
                    ->getSingleScalarResult();
    }

    public function getVolunteerGlobalCounts(array $structureIds) : int
    {
        return $this
            ->createQueryBuilder('v')
            ->select('COUNT(DISTINCT v)')
            ->join('v.structures', 's')
            ->andWhere('v.enabled = true')
            ->andWhere('s.enabled = true')
            ->andWhere('s.id IN (:structure_ids)')
            ->setParameter('structure_ids', $structureIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function filterDisabled(string $platform, array $volunteerIds) : array
    {
        return $this
            ->createQueryBuilder('v')
            ->select('v.id')
            ->where('v.enabled = false')
            ->andWhere('v.id IN (:volunteer_ids)')
            ->setParameter('volunteer_ids', $volunteerIds)
            ->andWhere('v.platform = :platform')
            ->setParameter('platform', $platform)
            ->getQuery()
            ->getArrayResult();
    }

    public function filterOptoutUntil(string $platform, array $volunteerIds) : array
    {
        return $this
            ->createQueryBuilder('v')
            ->select('v.id')
            ->where('v.enabled = true')
            ->andWhere('v.optoutUntil > :now')
            ->setParameter('now', new \DateTime())
            ->andWhere('v.id IN (:volunteer_ids)')
            ->setParameter('volunteer_ids', $volunteerIds)
            ->andWhere('v.platform = :platform')
            ->setParameter('platform', $platform)
            ->getQuery()
            ->getArrayResult();
    }

    public function filterPhoneLandline(string $platform, array $volunteerIds) : array
    {
        return $this
            ->createVolunteerListQueryBuilder($platform, $volunteerIds)
            ->select('v.id')
            ->join('v.phones', 'p')
            ->andWhere('v.phoneNumberOptin = true')
            ->andWhere('p.preferred = true')
            ->andWhere('p.mobile = false')
            ->getQuery()
            ->getArrayResult();
    }

    public function filterPhoneMissing(string $platform, array $volunteerIds) : array
    {
        return $this
            ->createVolunteerListQueryBuilder($platform, $volunteerIds)
            ->leftJoin('v.phones', 'p')
            ->andWhere('v.phoneNumberOptin = true')
            ->andWhere('p.id IS NULL')
            ->getQuery()
            ->getArrayResult();
    }

    public function filterPhoneOptout(string $platform, array $volunteerIds) : array
    {
        return $this
            ->createVolunteerListQueryBuilder($platform, $volunteerIds)
            ->leftJoin('v.phones', 'p')
            ->andWhere('v.phoneNumberOptin = false')
            ->andWhere('p.preferred = true')
            ->getQuery()
            ->getArrayResult();
    }

    public function filterEmailMissing(string $platform, array $volunteerIds) : array
    {
        return $this
            ->createVolunteerListQueryBuilder($platform, $volunteerIds)
            ->andWhere('v.email IS NULL')
            ->andWhere('v.emailOptin = true')
            ->getQuery()
            ->getArrayResult();
    }

    public function filterEmailOptout(string $platform, array $volunteerIds) : array
    {
        return $this
            ->createVolunteerListQueryBuilder($platform, $volunteerIds)
            ->andWhere('v.email IS NOT NULL')
            ->andWhere('v.emailOptin = false')
            ->getQuery()
            ->getArrayResult();
    }

    public function filterMinors(string $platform, array $volunteerIds) : array
    {
        return $this
            ->createVolunteerListQueryBuilder($platform, $volunteerIds)
            ->andWhere('v.minor = true')
            ->getQuery()
            ->getArrayResult();
    }

    public function getVolunteerTriggeringPriorities(array $volunteerIds) : array
    {
        $rows = $this->createQueryBuilder('v')
                     ->select('
                        v.id,
                        MIN(b.triggeringPriority) AS t1,
                        MIN(x.triggeringPriority) as t2,
                        MIN(p1.triggeringPriority) as t3,
                        MIN(p2.triggeringPriority) as t4,
                        MIN(p3.triggeringPriority) as t5,
                        MIN(p4.triggeringPriority) as t6
                    ')
                     ->join('v.badges', 'b')
                     ->leftJoin('b.synonym', 'x')
                     ->leftJoin('b.parent', 'p1')
                     ->leftJoin('p1.parent', 'p2')
                     ->leftJoin('p2.parent', 'p3')
                     ->leftJoin('p3.parent', 'p4')
                     ->where('v.id IN (:volunteer_ids)')
                     ->andWhere('b.enabled = true')
                     ->andWhere('p1.enabled = true')
                     ->andWhere('p2.enabled = true')
                     ->andWhere('p3.enabled = true')
                     ->andWhere('p4.enabled = true')
                     ->setParameter('volunteer_ids', $volunteerIds, Connection::PARAM_INT_ARRAY)
                     ->groupBy('v.id')
                     ->getQuery()
                     ->getArrayResult();

        $priorities = [];
        foreach ($rows as $row) {
            // Excluding null values (min([200, 300, null]) returns null)
            $values = [1000];
            for ($i = 1; $i <= 6; $i++) {
                if (null !== $row[sprintf('t%d', $i)]) {
                    $values[] = $row[sprintf('t%d', $i)];
                }
            }

            $priorities[] = [
                'id'       => $row['id'],
                'priority' => min($values),
            ];
        }

        return $priorities;
    }

    public function getVolunteerCountInStructure(Structure $structure) : int
    {
        return $this->createVolunteersQueryBuilder($structure->getPlatform())
                    ->select('COUNT(v.id)')
                    ->join('v.structures', 's')
                    ->andWhere('s.id = :structure')
                    ->setParameter('structure', $structure)
                    ->getQuery()
                    ->getSingleScalarResult();
    }

    public function getVolunteersHavingBadgeQueryBuilder(Badge $badge)
    {
        return $this->createVolunteersQueryBuilder($badge->getPlatform())
                    ->join('v.badges', 'b')
                    ->andWhere('b.id = :badge')
                    ->setParameter('badge', $badge)
                    ->andWhere('b.platform = :platform')
                    ->setParameter('platform', $badge->getPlatform());
    }

    /**
     * @return int[]
     */
    public function findVolunteersToAnonymize() : array
    {
        $rows = $this->createQueryBuilder('v')
                     ->select('v.id')
                     ->join(Pegass::class, 'p', 'WITH', 'v.externalId = p.externalId')
                     ->andWhere('p.enabled = false')
                     ->setMaxResults(1000)
                     ->getQuery()
                     ->getArrayResult();

        return array_column($rows, 'id');
    }

    private function createVolunteerListQueryBuilder(string $platform,
        array $volunteerIds,
        bool $onlyEnabled = true) : QueryBuilder
    {
        return $this
            ->createVolunteersQueryBuilder($platform, $onlyEnabled)
            ->andWhere('v.id IN (:volunteer_ids)')
            ->setParameter('volunteer_ids', $volunteerIds);
    }

    private function createVolunteersQueryBuilder(string $platform, bool $enabled = true) : QueryBuilder
    {
        $qb = $this->createQueryBuilder('v')
                   ->distinct()
                   ->andWhere('v.platform = :platform')
                   ->setParameter('platform', $platform);

        if ($enabled) {
            $qb->andWhere('v.enabled = true');
        }

        return $qb;
    }

    private function createAccessibleVolunteersQueryBuilder(User $user, bool $enabled = true) : QueryBuilder
    {
        return $this->createVolunteersQueryBuilder($user->getPlatform(), $enabled)
                    ->join('v.structures', 's')
                    ->join('s.users', 'u')
                    ->andWhere('u.id = :user')
                    ->setParameter('user', $user);
    }

    private function addSearchCriteria(QueryBuilder $qb, string $criteria)
    {
        $qb
            ->leftJoin('v.phones', 'p')
            ->andWhere(
                $qb->expr()->orX(
                    'v.externalId LIKE :criteria',
                    'v.firstName LIKE :criteria',
                    'v.lastName LIKE :criteria',
                    'p.e164 LIKE :criteria',
                    'p.national LIKE :criteria',
                    'p.international LIKE :criteria',
                    'v.email LIKE :criteria',
                    'CONCAT(v.firstName, \' \', v.lastName) LIKE :criteria',
                    'CONCAT(v.lastName, \' \', v.firstName) LIKE :criteria'
                )
            )
            ->setParameter('criteria', sprintf('%%%s%%', str_replace(' ', '%', ltrim($criteria, '0'))));
    }

    private function addUserCriteria(QueryBuilder $qb)
    {
        $qb
            ->join('v.user', 'vu');
    }

    private function addLockedCriteria(QueryBuilder $qb)
    {
        $qb
            ->andWhere('v.locked = true');
    }

    public function countActive() : int
    {
        return $this->createQueryBuilder('v')
                    ->select('COUNT(v)')
                    ->andWhere('v.enabled = true')
                    ->getQuery()
                    ->getSingleScalarResult();
    }

    public function getVolunteersFromList(
        VolunteerList $list,
        ?string $criteria,
        bool $hideDisabled,
        bool $filterUsers,
        bool $filterLocked,
        array $structureIds) : QueryBuilder
    {
        $qb = $this->createVolunteersQueryBuilder($list->getStructure()->getPlatform(), $hideDisabled)
                   ->join('v.lists', 'vl')
                   ->andWhere('vl.id = :list')
                   ->setParameter('list', $list);

        if ($criteria) {
            $this->addSearchCriteria($qb, $criteria);
        }

        if ($filterUsers) {
            $this->addUserCriteria($qb);
        }

        if ($filterLocked) {
            $this->addLockedCriteria($qb);
        }

        if (!empty($structureIds)) {
            $qb
                ->join('v.structures', 's')
                ->andWhere('s.id IN (:structures)')
                ->setParameter('structures', $structureIds);
        }

        return $qb;
    }
}
