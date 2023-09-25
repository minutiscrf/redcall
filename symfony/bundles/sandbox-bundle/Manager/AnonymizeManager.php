<?php

namespace Bundles\SandboxBundle\Manager;

use App\Entity\Phone;
use App\Entity\Volunteer;
use App\Manager\PhoneManager;
use App\Manager\VolunteerManager;
use App\Settings;
use Bundles\SettingsBundle\Manager\SettingManager;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\HttpKernel\KernelInterface;

class AnonymizeManager
{
    /**
     * @var VolunteerManager
     */
    private $volunteerManager;

    /**
     * @var SettingManager
     */
    private $settingManager;

    /**
     * @var FakeSmsManager
     */
    private $fakeSmsManager;

    /**
     * @var FakeEmailManager
     */
    private $fakeEmailManager;

    /**
     * @var KernelInterface
     */
    private $kernel;

    /**
     * @var PhoneManager
     */
    private $phoneManager;

    public function __construct(VolunteerManager $volunteerManager,
        SettingManager $settingManager,
        FakeSmsManager $fakeSmsManager,
        FakeEmailManager $fakeEmailManager,
        KernelInterface $kernel,
        PhoneManager $phoneManager)
    {
        $this->volunteerManager = $volunteerManager;
        $this->settingManager   = $settingManager;
        $this->fakeSmsManager   = $fakeSmsManager;
        $this->fakeEmailManager = $fakeEmailManager;
        $this->kernel           = $kernel;
        $this->phoneManager     = $phoneManager;
    }

    public static function generateFirstname() : string
    {
        static $firstnames = null;

        if (null === $firstnames) {
            $path       = __DIR__.'/../../../assets/db/firstnames.txt.gz';
            $firstnames = explode("\n", file_get_contents('compress.zlib://'.$path));
        }

        return $firstnames[rand() % count($firstnames)];
    }

    public static function generateLastname() : string
    {
        static $lastnames = null;

        if (null === $lastnames) {
            $path      = __DIR__.'/../../../assets/db/lastnames.txt.gz';
            $lastnames = explode("\n", file_get_contents('compress.zlib://'.$path));
        }

        return $lastnames[rand() % count($lastnames)];
    }

    public static function generatePhoneNumber() : string
    {
        $phone = sprintf(
            '0%d %d%d %d%d %d%d %d%d',
            6 + rand() % 2,
            rand() % 10, rand() % 10,
            rand() % 10, rand() % 10,
            rand() % 10, rand() % 10,
            rand() % 10, rand() % 10
        );

        $phoneUtil = PhoneNumberUtil::getInstance();
        $parsed    = $phoneUtil->parse($phone, 'FR');

        return $phoneUtil->format($parsed, PhoneNumberFormat::E164);
    }

    public static function generateEmail(string $firstname, string $lastname) : string
    {
        $providers = [
            'example.org',
        ];

        return strtolower(sprintf('%s.%s@%s', substr($firstname, 0, 1), $lastname, $providers[rand() % count($providers)]));
    }

    /**
     * Only used for pen-test environments
     */
    public function anonymizeDatabase()
    {
        if ('cli' === php_sapi_name()) {
            $this->fakeSmsManager->truncate();
            $this->fakeEmailManager->truncate();

            $this->volunteerManager->foreach(function (Volunteer $volunteer) {
                $this->anonymize($volunteer);
            });
        } elseif (time() - $this->settingManager->get(Settings::SANDBOX_LAST_ANONYMIZE, 0) > 86400) {
            $this->settingManager->set(Settings::SANDBOX_LAST_ANONYMIZE, time());

            // Executing asynchronous task to prevent against interruptions
            $console = sprintf('%s/bin/console', $this->kernel->getProjectDir());
            $command = sprintf('%s anonymize', escapeshellarg($console));
            exec(sprintf('%s > /dev/null 2>&1 & echo -n \$!', $command));
        }
    }

    public function anonymizeVolunteer(string $externalId, string $platform)
    {
        $volunteer = $this->volunteerManager->findOneByExternalId($platform, $externalId);
        if ($volunteer) {
            $this->anonymize($volunteer);
        }
    }

    /**
     * Keep volunteer's nivol & skills, anonymize everything else.
     * Volunteer gets automatically locked & disabled.
     *
     * @param Volunteer $volunteer
     */
    private function anonymize(Volunteer $volunteer)
    {
        $volunteer->setFirstName($this->generateFirstname());
        $volunteer->setLastName($this->generateLastname());

        $volunteer->setEmail($this->generateEmail($volunteer->getFirstName(), $volunteer->getLastName()));
        $volunteer->getPhones()->clear();

        if (!$volunteer->getId()) {
            $this->volunteerManager->save($volunteer);
        }

        do {
            $phoneNumber = $this->generatePhoneNumber();
            if (!$this->phoneManager->findOneByPhoneNumber($phoneNumber)) {
                break;
            }
        } while (true);

        $phone = new Phone();
        $phone->setVolunteer($volunteer);
        $phone->setE164($phoneNumber);
        $phone->setMobile(true);
        $phone->setPreferred(true);
        $volunteer->getPhones()->add($phone);

        $this->volunteerManager->save($volunteer);
    }
}
