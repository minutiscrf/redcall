<?php

namespace App\Manager;

use App\Entity\Badge;
use App\Entity\Phone;
use App\Entity\Structure;
use App\Entity\Volunteer;
use App\Enum\Platform;
use App\Task\SyncOneWithPegass;
use Bundles\GoogleTaskBundle\Service\TaskSender;
use Bundles\PegassCrawlerBundle\Entity\Pegass;
use Bundles\PegassCrawlerBundle\Manager\PegassManager;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Refreshes Redcall database based on Pegass cache
 *
 * Query to check inconsistencies:
 *
 * select count(*)
 * from pegass p
 * left join volunteer v on v.nivol = trim(leading '0' from p.identifier)
 * where p.type = 'volunteer'
 * and p.enabled = 1
 * and v.id is null
 */
class RefreshManager
{
    private const RED_CROSS_DOMAINS = [
        'croix-rouge.fr',
    ];

    // People having that badge should be enabled as RedCall users, and set as admin
    const BADGE_ADMIN = 'RTMR';

    /**
     * @var PegassManager
     */
    private $pegassManager;

    /**
     * @var StructureManager
     */
    private $structureManager;

    /**
     * @var VolunteerManager
     */
    private $volunteerManager;

    /**
     * @var BadgeManager
     */
    private $badgeManager;

    /**
     * @var CategoryManager
     */
    private $categoryManager;

    /**
     * @var UserManager
     */
    private $userManager;

    /**
     * @var PhoneManager
     */
    private $phoneManager;

    /**
     * @var TaskSender
     */
    private $async;

    /**
     * @var LoggerInterface|null
     */
    private $logger;

    public function __construct(PegassManager $pegassManager,
        StructureManager $structureManager,
        VolunteerManager $volunteerManager,
        BadgeManager $badgeManager,
        CategoryManager $categoryManager,
        UserManager $userManager,
        PhoneManager $phoneManager,
        TaskSender $async,
        LoggerInterface $logger = null)
    {
        $this->pegassManager    = $pegassManager;
        $this->structureManager = $structureManager;
        $this->volunteerManager = $volunteerManager;
        $this->badgeManager     = $badgeManager;
        $this->categoryManager  = $categoryManager;
        $this->userManager      = $userManager;
        $this->phoneManager     = $phoneManager;
        $this->async            = $async;
        $this->logger           = $logger ?: new NullLogger();
    }

    public function refresh(bool $force)
    {
        $this->refreshStructures($force);
        $this->refreshVolunteers($force);
    }

    public function refreshAsync()
    {
        foreach ($this->pegassManager->getAllEnabledEntities() as $row) {
            $this->async->fire(SyncOneWithPegass::class, $row);
        }

        $this->async->fire(SyncOneWithPegass::class, [
            'type'       => SyncOneWithPegass::PARENT_STRUCUTRES,
            'identifier' => null,
        ]);

        $this->async->fire(SyncOneWithPegass::class, [
            'type'       => SyncOneWithPegass::SYNC_STRUCTURES,
            'identifier' => null,
        ]);

        $this->async->fire(SyncOneWithPegass::class, [
            'type'       => SyncOneWithPegass::SYNC_VOLUNTEERS,
            'identifier' => null,
        ]);
    }

    public function refreshStructures(bool $force)
    {
        $this->structureManager->synchronizeWithPegass();

        // Import or refresh structures
        $this->pegassManager->foreach(Pegass::TYPE_STRUCTURE, function (Pegass $pegass) use ($force) {
            $this->debug('Walking through a structure', [
                'identifier' => $pegass->getIdentifier(),
            ]);

            $this->refreshStructure($pegass, $force);
        });

        $this->refreshParentStructures();
    }

    public function refreshStructure(Pegass $pegass, bool $force)
    {
        if (!$pegass->evaluate('structure.id')) {
            return;
        }

        $structure = $this->structureManager->findOneByExternalId(Platform::FR, $pegass->getIdentifier());

        if ($structure && $structure->isLocked()) {
            return;
        }

        if (!$structure) {
            $structure = new Structure();
            $structure->setPlatform(Platform::FR);
        }

        // Structure already up to date
        if (!$force && $structure->getLastPegassUpdate()
            && $structure->getLastPegassUpdate()->getTimestamp() === $pegass->getUpdatedAt()->getTimestamp()) {
            return;
        }

        $structure->setLastPegassUpdate(clone $pegass->getUpdatedAt());
        $structure->setEnabled(true);

        $this->debug('Updating a structure', [
            'type'              => $pegass->getType(),
            'identifier'        => $pegass->getIdentifier(),
            'parent-identifier' => $pegass->getParentIdentifier(),
        ]);

        $structure->setExternalId($pegass->evaluate('structure.id'));
        $structure->setName($pegass->evaluate('structure.libelle'));
        $structure->setPresident(ltrim($pegass->evaluate('responsible.responsableId'), '0'));
        $this->structureManager->save($structure);
    }

    public function refreshParentStructures()
    {
        $this->pegassManager->foreach(Pegass::TYPE_STRUCTURE, function (Pegass $pegass) {
            $this->debug('Updating parent structures for a structure', [
                'identifier'        => $pegass->getIdentifier(),
                'parent_identifier' => $pegass->getParentIdentifier(),
            ]);

            if ($parentId = $pegass->evaluate('structure.parent.id')) {
                $structure = $this->structureManager->findOneByExternalId(Platform::FR, $pegass->getIdentifier());

                if ($structure->getParentStructure() && $parentId === $structure->getParentStructure()->getExternalId()) {
                    return;
                }

                if ($parent = $this->structureManager->findOneByExternalId(Platform::FR, $parentId)) {
                    if (!in_array($structure, $parent->getAncestors())) {
                        $structure->setParentStructure($parent);
                        $this->structureManager->save($structure);
                    } else {
                        $this->logger->error(sprintf(
                            'Hierarchy loop: structure %s has parent %s which itself has %s as ancestor!',
                            $structure->getDisplayName(),
                            $parent->getDisplayName(),
                            $structure->getDisplayName()
                        ));
                    }
                }
            }
        });
    }

    public function refreshVolunteers(bool $force)
    {
        $this->volunteerManager->synchronizeWithPegass();

        $this->pegassManager->foreach(Pegass::TYPE_VOLUNTEER, function (Pegass $pegass) use ($force) {
            $this->debug('Walking through a volunteer', [
                'identifier' => $pegass->getIdentifier(),
            ]);

            // Volunteer is invalid (ex: 00000048004C)
            if (!$pegass->evaluate('user.id')) {
                return;
            }

            $this->refreshVolunteer($pegass, $force);
        });
    }

    public function refreshVolunteer(Pegass $pegass, bool $force)
    {
        // Create or update?
        $volunteer = $this->volunteerManager->findOneByNivol(Platform::FR, $pegass->getIdentifier());
        if (!$volunteer) {
            $volunteer = new Volunteer();
            $volunteer->setPlatform(Platform::FR);
        }

        $volunteer->setExternalId(ltrim($pegass->getIdentifier(), '0'));
        $volunteer->setReport([]);

        // Update structures based on where volunteer was found while crawling structures
        $structureIdsVolunteerBelongsTo = [];
        foreach (array_filter(explode('|', $pegass->getParentIdentifier())) as $identifier) {
            if ($structure = $this->structureManager->findOneByExternalId(Platform::FR, $identifier)) {
                $volunteer->addStructure($structure);
                $structureIdsVolunteerBelongsTo[] = $structure->getId();
            }
        }

        // Add structures based on the actions performed by the volunteer
        $identifiers = [];
        foreach ($pegass->evaluate('actions') ?? [] as $action) {
            if (isset($action['structure']['id']) && !in_array($action['structure']['id'], $identifiers)) {
                if ($structure = $this->structureManager->findOneByExternalId(Platform::FR, $action['structure']['id'])) {
                    $volunteer->addStructure($structure);
                    $structureIdsVolunteerBelongsTo[] = $structure->getId();
                }
                $identifiers[] = $action['structure']['id'];
            }
        }

        // Volunteer is locked
        if ($volunteer->isLocked()) {
            $volunteer->addReport('import_report.update_locked');
            $this->volunteerManager->save($volunteer);

            // If volunteer is bound to a RedCall user, update its structures
            $user = $this->userManager->findOneByExternalId(Platform::FR, $volunteer->getExternalId());
            if ($user) {
                $this->userManager->changeVolunteer(Platform::FR, $user, $volunteer->getExternalId());
            }

            $this->checkAdminRole($volunteer);

            return;
        }

        // Remove volunteer from structures he does not belong to anymore
        $structuresToRemove = [];
        foreach ($volunteer->getStructures() as $structure) {
            if (!in_array($structure->getId(), $structureIdsVolunteerBelongsTo)) {
                $structuresToRemove[] = $structure;
            }
        }
        foreach ($structuresToRemove as $structure) {
            $volunteer->removeStructure($structure);
        }

        // Volunteer disabled on Pegass side
        $enabled = $pegass->evaluate('user.actif');
        if (!$enabled) {
            $volunteer->addReport('import_report.disabled');
            $volunteer->setEnabled(false);
            $this->volunteerManager->save($volunteer);

            $this->checkAdminRole($volunteer);

            return;
        }

        // Volunteer already up to date
        if (!$force && $volunteer->getLastPegassUpdate()
            && $volunteer->getLastPegassUpdate()->getTimestamp() === $pegass->getUpdatedAt()->getTimestamp()) {
            $this->volunteerManager->save($volunteer);

            $this->checkAdminRole($volunteer);

            return;
        }

        $this->debug('Updating a volunteer', [
            'type'              => $pegass->getType(),
            'identifier'        => $pegass->getIdentifier(),
            'parent-identifier' => $pegass->getParentIdentifier(),
        ]);

        $volunteer->setLastPegassUpdate(clone $pegass->getUpdatedAt());

        if (!$pegass->evaluate('user.id')) {
            $volunteer->addReport('import_report.failed');
            $this->volunteerManager->save($volunteer);

            $this->checkAdminRole($volunteer);

            return;
        }

        $volunteer->setEnabled(true);

        // Update basic information
        $volunteer->setFirstName($this->normalizeName($pegass->evaluate('user.prenom')));
        $volunteer->setLastName($this->normalizeName($pegass->evaluate('user.nom')));

        // Update birth day
        $birthday = substr($pegass->evaluate('infos.dateNaissance'), 0, 10);

        if (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $birthday)) {
            $volunteer->setBirthday(new \DateTime($birthday));
        }

        if (!$volunteer->isPhoneNumberLocked()) {
            $this->fetchPhoneNumber($volunteer, $pegass->evaluate('contact'));
        }

        if (!$volunteer->isEmailLocked()) {
            $volunteer->setEmail($this->fetchEmail($pegass->evaluate('infos'), $pegass->evaluate('contact')));
        }

        // Update volunteer badges
        $volunteer->setExternalBadges(
            $this->fetchBadges($pegass)
        );

        // Disabling minors
        if ($volunteer->isMinor()) {
            $volunteer->addReport('import_report.minor');
            $volunteer->setEnabled(false);
            $this->volunteerManager->save($volunteer);

            $this->checkAdminRole($volunteer);

            return;
        }

        $this->volunteerManager->save($volunteer);

        // If volunteer is bound to a RedCall user, update its structures
        $user = $volunteer->getUser();
        if ($user) {
            $this->userManager->changeVolunteer(Platform::FR, $user, $volunteer->getExternalId());
        }

        $this->checkAdminRole($volunteer);
    }

    private function checkAdminRole(Volunteer $volunteer)
    {
        if (!$volunteer->isEnabled() && $user = $volunteer->getUser()) {
            $user->setIsTrusted(false);
            $this->userManager->save($user);

            return;
        }

        if ($volunteer->hasBadge(Platform::FR, self::BADGE_ADMIN)) {
            $this->volunteerManager->save($volunteer);
            $this->userManager->createUser(Platform::FR, $volunteer->getNivol());
            $user = $this->userManager->findOneByExternalId(Platform::FR, $volunteer->getNivol());
            $user->setIsAdmin(true);
            $this->userManager->save($user);
        }

        // TODO: once current admins have the RTMR badge, automate the admin desactivation

    }

    private function normalizeName(string $name) : string
    {
        return sprintf('%s%s',
            mb_strtoupper(mb_substr($name, 0, 1)),
            mb_strtolower(mb_substr($name, 1))
        );
    }

    private function fetchPhoneNumber(Volunteer $volunteer, array $contact)
    {
        $phoneKeys = ['POR', 'PORT', 'TELDOM', 'TELTRAV', 'PORE'];

        // Filter out keys that are not phones
        $contact = array_filter($contact, function ($data) use ($phoneKeys) {
            return in_array($data['moyenComId'] ?? [], $phoneKeys);
        });

        // Order phones in order to take work phone last
        usort($contact, function ($a, $b) use ($phoneKeys) {
            return array_search($a['moyenComId'], $phoneKeys) <=> array_search($b['moyenComId'], $phoneKeys);
        });

        if (!$contact) {
            return;
        }

        $phoneUtil = PhoneNumberUtil::getInstance();
        foreach ($contact as $key => $row) {
            try {
                /** @var PhoneNumber $parsed */
                $parsed = $phoneUtil->parse($row['libelle'], Phone::DEFAULT_LANG);
                $e164   = $phoneUtil->format($parsed, PhoneNumberFormat::E164);
                if (!$volunteer->hasPhoneNumber($e164)) {
                    $existingPhone = $this->phoneManager->findOneByPhoneNumber($e164);
                    // Allow a volunteer to take disabled people's phone number
                    if ($existingPhone && !$existingPhone->getVolunteer()->isEnabled()) {
                        $existingVolunteer = $existingPhone->getVolunteer();
                        $existingVolunteer->removePhone($existingPhone);
                        $this->volunteerManager->save($existingVolunteer);
                        $existingPhone = null;
                    }
                    if (!$existingPhone) {
                        $phone = new Phone();
                        $phone->setPreferred(0 === $volunteer->getPhones()->count());
                        $phone->setE164($e164);
                        $volunteer->addPhone($phone);
                    }
                }
            } catch (NumberParseException $e) {
                continue;
            }
        }
    }

    private function fetchEmail(array $infos, array $contact) : ?string
    {
        $emailKeys = ['MAIL', 'MAILDOM', 'MAILTRAV'];

        // Filter out keys that are not emails
        $contact = array_filter($contact, function ($data) use ($emailKeys) {
            return in_array($data['moyenComId'] ?? [], $emailKeys)
                   && preg_match('/^.+\@.+\..+$/', $data['libelle'] ?? false);
        });

        // If volunteer has a favorite email, we return it
        if ($no = ($infos['mailMoyenComId']['numero'] ?? null)) {
            foreach ($contact as $item) {
                if ($no === ($item['numero'] ?? null)) {
                    return $item['libelle'];
                }
            }
        }

        // Order emails
        usort($contact, function ($a, $b) use ($emailKeys) {

            // Red cross emails should be put last
            foreach (self::RED_CROSS_DOMAINS as $domain) {
                if (false !== stripos($a['libelle'] ?? false, $domain)) {
                    return 1;
                }
                if (false !== stripos($b['libelle'] ?? false, $domain)) {
                    return -1;
                }
            }

            return array_search($a['moyenComId'], $emailKeys) <=> array_search($b['moyenComId'], $emailKeys);
        });

        if (!$contact) {
            return null;
        }

        return reset($contact)['libelle'];
    }

    private function fetchBadges(Pegass $pegass)
    {
        return array_merge(
            $this->fetchActionBadges($pegass->evaluate('actions')),
            $this->fetchSkillBadges($pegass->evaluate('skills')),
            $this->fetchTrainingBadges($pegass->evaluate('trainings')),
            $this->fetchNominationBadges($pegass->evaluate('nominations'))
        );
    }

    private function fetchActionBadges(array $data) : array
    {
        $badges = [];

        foreach (['action', 'groupeAction'] as $type) {
            foreach ($data as $action) {
                if (!is_array($action)) {
                    continue;
                }

                $externalId = sprintf('%s-%d', $type, $action[$type]['id']);
                $badge      = $this->badgeManager->findOneByExternalId(Platform::FR, $externalId);

                if (!$badge) {
                    $badge = $this->createBadge($externalId, $action[$type]['libelle']);
                }

                $badges[] = $badge;
            }
        }

        return $badges;
    }

    private function fetchSkillBadges(array $data) : array
    {
        $badges = [];

        foreach ($data as $skill) {
            if (!is_array($skill)) {
                continue;
            }

            $externalId = sprintf('skill-%d', $skill['id']);
            $badge      = $this->badgeManager->findOneByExternalId(Platform::FR, $externalId);

            if (!$badge) {
                $badge = $this->createBadge($externalId, $skill['libelle']);
            }

            $badges[] = $badge;
        }

        return $badges;
    }

    private function fetchTrainingBadges(array $data) : array
    {
        $badges = [];

        foreach ($data as $training) {
            if (!is_array($training)) {
                continue;
            }

            // Ignore trainings that should be retrained since more than 6 months
            if (isset($training['dateRecyclage']) && preg_match('/^\d{4}\-\d{2}\-\d{2}T\d{2}:\d{2}:\d{2}$/', $training['dateRecyclage'])) {
                $expiration = (new \DateTime($training['dateRecyclage']))->add(new \DateInterval('P6M'));
                if (time() > $expiration->getTimestamp()) {
                    continue;
                }
            }

            $externalId = sprintf('training-%d', $training['formation']['id']);
            $badge      = $this->badgeManager->findOneByExternalId(Platform::FR, $externalId);

            if (!$badge) {
                $badge = $this->createBadge($externalId, $training['formation']['code'], $training['formation']['libelle']);
            }

            $badges[] = $badge;
        }

        return $badges;
    }

    private function fetchNominationBadges(array $data) : array
    {
        $badges = [];

        foreach ($data as $nomination) {
            if (!is_array($nomination)) {
                continue;
            }

            $externalId = sprintf('nomination-%d', $nomination['id']);
            $badge      = $this->badgeManager->findOneByExternalId(Platform::FR, $externalId);

            if (!$badge) {
                $badge = $this->createBadge($externalId, $nomination['libelleCourt'], $nomination['libelleLong']);
            }

            $badges[] = $badge;
        }

        return $badges;
    }

    private function createBadge(string $externalId, string $name, ?string $description = null) : Badge
    {
        if (!$description) {
            $description = $name;
        }

        $badge = new Badge();
        $badge->setPlatform(Platform::FR);
        $badge->setExternalId($externalId);
        $badge->setName(substr($name, 0, 64));
        $badge->setDescription(substr($description, 0, 255));
        $this->badgeManager->save($badge);

        return $badge;
    }

    private function debug(string $message, array $params = [])
    {
        $this->logger->info($message, $params);

        if ('cli' === php_sapi_name()) {
            echo sprintf('%s %s (%s)', date('d/m/Y H:i:s'), $message, json_encode($params)), PHP_EOL;
        }
    }
}