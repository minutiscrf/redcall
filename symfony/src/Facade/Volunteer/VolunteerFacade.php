<?php

namespace App\Facade\Volunteer;

use Bundles\ApiBundle\Annotation\Facade;
use Bundles\ApiBundle\Contracts\FacadeInterface;
use Symfony\Component\Serializer\Annotation\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

class VolunteerFacade implements FacadeInterface
{
    /**
     * An unique identifier for the volunteer.
     *
     * You can use a random UUID, a name or the same identifier as in your own application.
     *
     * @Assert\NotBlank(groups={"create"})
     * @Assert\Length(max = 64)
     *
     * @var string|null
     */
    protected $externalId;

    /**
     * Volunteer's first name.
     *
     * @Assert\NotBlank(groups={"create"})
     * @Assert\Length(max = 80)
     *
     * @var string|null
     */
    protected $firstName;

    /**
     * Volunteer's last name.
     *
     * @Assert\NotBlank(groups={"create"})
     * @Assert\Length(max = 80)
     *
     * @var string|null
     */
    protected $lastName;

    /**
     * Volunteer's birth day.
     *
     * In the YYYY-MM-DD format.
     *
     * @Assert\Date()
     *
     * @var string|null
     */
    protected $birthday;

    /**
     * Volunteer's "Be Right Back" date.
     *
     * If volunteer doesn't want to be triggered for some time (for example because of holidays),
     * this date (in the YYYY-MM-DD format) can be set.
     *
     * @Assert\Date()
     *
     * @var string|null
     */
    protected $optoutUntil;

    /**
     * Volunteer's email
     *
     * @Assert\Length(max=80)
     * @Assert\Email
     *
     * @var string|null
     */
    protected $email;

    /**
     * Whether volunteer accepts to receive emails.
     *
     * @Assert\Choice(choices={false, true})
     *
     * @var bool|null
     */
    protected $emailOptin;

    /**
     * Whether volunteer's email cannot be updated from the API.
     *
     * It happens if volunteer manually changed its email on the RedCall interface,
     * it becomes locked in order to prevent automatic synchronization with external
     * sources.
     *
     * @Assert\Choice(choices={false, true})
     *
     * @var bool|null
     */
    protected $emailLocked;

    /**
     * Whether volunteer accepts to receive SMS and voice calls.
     *
     * @Assert\Choice(choices={false, true})
     *
     * @var bool|null
     */
    protected $phoneOptin;

    /**
     * Whether volunteer's phone numbers cannot be updated from the API.
     *
     * It happens if volunteer manually changed its phone numbers on the RedCall interface,
     * it becomes locked in order to prevent automatic synchronization with external
     * sources.
     *
     * @Assert\Choice(choices={false, true})
     *
     * @var bool|null
     */
    protected $phoneLocked;

    /**
     * Identifier of the user tied to that volunteer
     *
     * If the volunteer can trigger other volunteers, it is tied to a user resource,
     * which contain all its RedCall authorizations (which structures (s)he can trigger,
     * whether (s)he is an administrator who can access all triggers etc.
     *
     * In order to unbind a user from a volunteer, use boolean false.
     *
     * @Assert\Length(max=64)
     * @Assert\Email
     *
     * @SerializedName("user_email")
     *
     * @var string|bool|null
     */
    protected $userIdentifier;

    static public function getExample(Facade $decorates = null) : FacadeInterface
    {
        $facade = new static;

        $facade->externalId  = 'demo-volunteer';
        $facade->firstName   = 'John';
        $facade->lastName    = 'Doe';
        $facade->birthday    = '1984-07-10';
        $facade->optoutUntil = null;
        $facade->email       = 'demo@example.org';
        $facade->emailOptin  = true;
        $facade->emailLocked = false;
        $facade->phoneOptin  = true;
        $facade->phoneLocked = false;

        return $facade;
    }

    public function getExternalId() : ?string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId) : VolunteerFacade
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getFirstName() : ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName) : VolunteerFacade
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName() : ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName) : VolunteerFacade
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getBirthday() : ?string
    {
        return $this->birthday;
    }

    public function setBirthday(?string $birthday) : VolunteerFacade
    {
        $this->birthday = $birthday;

        return $this;
    }

    public function getOptoutUntil() : ?string
    {
        return $this->optoutUntil;
    }

    public function setOptoutUntil(?string $optoutUntil) : VolunteerFacade
    {
        $this->optoutUntil = $optoutUntil;

        return $this;
    }

    public function getEmail() : ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email) : VolunteerFacade
    {
        $this->email = $email;

        return $this;
    }

    public function getEmailOptin() : ?bool
    {
        return $this->emailOptin;
    }

    public function setEmailOptin(?bool $emailOptin) : VolunteerFacade
    {
        $this->emailOptin = $emailOptin;

        return $this;
    }

    public function getEmailLocked() : ?bool
    {
        return $this->emailLocked;
    }

    public function setEmailLocked(?bool $emailLocked) : VolunteerFacade
    {
        $this->emailLocked = $emailLocked;

        return $this;
    }

    public function getPhoneOptin() : ?bool
    {
        return $this->phoneOptin;
    }

    public function setPhoneOptin(?bool $phoneOptin) : VolunteerFacade
    {
        $this->phoneOptin = $phoneOptin;

        return $this;
    }

    public function getPhoneLocked() : ?bool
    {
        return $this->phoneLocked;
    }

    public function setPhoneLocked(?bool $phoneLocked) : VolunteerFacade
    {
        $this->phoneLocked = $phoneLocked;

        return $this;
    }

    /**
     * @return bool|string|null
     */
    public function getUserIdentifier()
    {
        return $this->userIdentifier;
    }

    /**
     * @param string|bool|null $userIdentifier
     */
    public function setUserIdentifier($userIdentifier) : VolunteerFacade
    {
        $this->userIdentifier = $userIdentifier;

        return $this;
    }
}