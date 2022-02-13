<?php

namespace App\Manager;

use App\Entity\Pegass;
use App\Event\PegassEvent;
use App\PegassEvents;
use App\Repository\PegassRepository;
use Bundles\GoogleTaskBundle\Service\TaskSender;
use DateTime;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class PegassManager
{
    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var PegassRepository
     */
    private $pegassRepository;

    /**
     * @var LoggerInterface
     */
    private $slackLogger;

    /**
     * @var TaskSender
     */
    private $taskSender;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(EventDispatcherInterface $eventDispatcher,
        PegassRepository $pegassRepository,
        LoggerInterface $slackLogger,
        TaskSender $taskSender,
        LoggerInterface $logger)
    {
        $this->eventDispatcher  = $eventDispatcher;
        $this->pegassRepository = $pegassRepository;
        $this->slackLogger      = $slackLogger;
        $this->taskSender       = $taskSender;
        $this->logger           = $logger;
    }

    public function getAllEnabledEntities() : array
    {
        return $this->pegassRepository->getAllEnabledEntities();
    }

    public function getEnabledEntitiesQueryBuilder(?string $type, ?string $identifier) : QueryBuilder
    {
        return $this->pegassRepository->getEnabledEntitiesQueryBuilder($type, $identifier);
    }

    public function updateEntity(Pegass $entity, array $content)
    {
        // Just in case entity would not be managed anymore
        $entity = $this->pegassRepository->find($entity->getId());

        $entity->setContent($content);
        $entity->setEnabled(true);
        $entity->setUpdatedAt(new \DateTime());

        $this->pegassRepository->save($entity);

        switch ($entity->getType()) {
            case Pegass::TYPE_STRUCTURE:
                $this->updateStructure($entity);
                break;
            case Pegass::TYPE_VOLUNTEER:
                $this->updateVolunteer($entity);
                break;
        }

        $this->dispatchEvent($entity);
    }

    public function foreach(string $type, callable $callback, bool $onlyEnabled = true)
    {
        $this->pegassRepository->foreach($type, $callback, $onlyEnabled);
    }

    public function getEntity(string $type, string $identifier, bool $onlyEnabled = true) : ?Pegass
    {
        if (Pegass::TYPE_VOLUNTEER === $type) {
            $identifier = str_pad($identifier, 12, '0', STR_PAD_LEFT);
        }

        return $this->pegassRepository->getEntity($type, $identifier, $onlyEnabled);
    }

    public function removeMissingEntities(string $type, array $identifiers)
    {
        $entities = $this->pegassRepository->findMissingEntities($type, $identifiers);

        foreach ($entities as $entity) {
            $entity->setEnabled(false);
            $this->pegassRepository->save($entity);
        }
    }

    public function createNewEntity(string $type, string $identifier, string $parentIdentifier)
    {
        $entity = new Pegass();
        $entity->setType($type);
        $entity->setIdentifier($identifier);
        $entity->setParentIdentifier($parentIdentifier);
        $entity->setUpdatedAt(new DateTime('1984-07-10'));

        $this->debug($entity, sprintf('Creating %s', $type));

        $this->pegassRepository->save($entity);

        return $entity;
    }

    public function updateStructure(Pegass $entity)
    {
        if (!$structure = $entity->getContent()) {
            return;
        }

        $pages = $structure['volunteers'];

        $entity->setContent($structure);
        $entity->setUpdatedAt(new DateTime());

        $wasEnabled = $entity->getEnabled();
        $entity->setEnabled(true);

        $parentIdentifier = sprintf('|%s|', $entity->getIdentifier());

        $identifiers = [];
        foreach ($pages as $page) {
            $list        = $page['list'] ?? $page['content'] ?? [];
            $identifiers = array_merge($identifiers, array_column($list, 'id'));
        }

        if ($identifiers) {
            $missingEntities = $this->pegassRepository->findMissingEntities(Pegass::TYPE_VOLUNTEER, $identifiers, $parentIdentifier);
        } else {
            $missingEntities = $this->pegassRepository->findAllChildrenEntities(Pegass::TYPE_VOLUNTEER, $parentIdentifier);
            //
            //            $entity->setEnabled(false);
            //
            //            if ($wasEnabled) {
            //                $this->slackLogger->warning(sprintf(
            //                    'Disabling structure %s (%s)',
            //                    $entity->evaluate('structure.libelle'),
            //                    $entity->getIdentifier()
            //                ));
            //            }
        }

        // Removing the structure from volunteers that do not belong to it anymore
        foreach ($missingEntities as $missingEntity) {
            $missingEntity->setParentIdentifier(str_replace($parentIdentifier, '|', $missingEntity->getParentIdentifier()));

            if ('|' === $missingEntity->getParentIdentifier()) {
                $missingEntity->setParentIdentifier(null);
                $missingEntity->setEnabled(false);
            }

            $missingEntity->setUpdatedAt(new \DateTime('1984-07-10')); // Expired
            $this->pegassRepository->save($missingEntity);
        }

        $this->pegassRepository->save($entity);

        foreach ($pages as $page) {
            $list = $page['list'] ?? $page['content'] ?? [];
            foreach ($list as $row) {
                $volunteer = $this->pegassRepository->getEntity(Pegass::TYPE_VOLUNTEER, $row['id'], false);
                if (!$volunteer) {
                    $volunteer = new Pegass();
                    $volunteer->setType(Pegass::TYPE_VOLUNTEER);
                    $volunteer->setIdentifier($row['id']);
                    $volunteer->setParentIdentifier($parentIdentifier);
                    $volunteer->setUpdatedAt(new DateTime('1984-07-10')); // Expired
                    $this->debug($volunteer, 'Creating volunteer');
                    $this->pegassRepository->save($volunteer);
                } else {
                    if (false === strpos($volunteer->getParentIdentifier(), $parentIdentifier)) {
                        $volunteer->setParentIdentifier(
                            sprintf('%s%s|', $volunteer->getParentIdentifier() ?? '|', $entity->getIdentifier())
                        );
                        $this->updateVolunteer($volunteer);
                        $this->dispatchEvent($volunteer);
                    }
                }
            }
        }
    }

    public function updateVolunteer(Pegass $entity)
    {
        if (!$data = $entity->getContent()) {
            return;
        }

        $entity->setContent($data);
        $entity->setUpdatedAt(new DateTime());

        $this->pegassRepository->save($entity);
    }

    public function flush()
    {
        $this->pegassRepository->clear();
    }

    private function debug(Pegass $entity, string $message, array $data = [])
    {
        $params = array_merge($data, [
            'at'                => date('d/m/Y H:i'),
            'type'              => $entity->getType(),
            'identifier'        => $entity->getIdentifier(),
            'parent_identifier' => $entity->getParentIdentifier(),
        ]);

        $this->logger->debug($message, $params);

        echo sprintf('%s %s (%s)', date('d/m/Y H:i:s'), $message, json_encode($params)), PHP_EOL;
    }

    private function dispatchEvent(Pegass $entity)
    {
        switch ($entity->getType()) {
            case Pegass::TYPE_STRUCTURE:
                $this->eventDispatcher->dispatch(new PegassEvent($entity), PegassEvents::UPDATE_STRUCTURE);
                break;
            case Pegass::TYPE_VOLUNTEER:
                $this->eventDispatcher->dispatch(new PegassEvent($entity), PegassEvents::UPDATE_VOLUNTEER);
                break;
        }
    }
}