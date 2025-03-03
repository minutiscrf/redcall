<?php

namespace App\Entity;

use App\Repository\MessageToGuestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sometimes, we need to send messages to people that are not volunteers.
 * Right now, the data structure doesn't allow it.
 *
 * This entity provides a way to store main information related to a message
 * without needing a volunteer, a campaign, a communication and so on, but
 * keeps billing information.
 *
 * @ORM\Entity(repositoryClass=MessageToGuestRepository::class)
 */
class MessageToGuest
{
    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", length=80)
     */
    private $type;

    /**
     * @ORM\Column(type="string", length=255)
     */
    private $destination;

    /**
     * @ORM\Column(type="text")
     */
    private $body;

    /**
     * @ORM\Column(type="string", length=64, nullable=true)
     */
    private $messageId;

    /**
     * @ORM\Column(type="string", length=16)
     */
    private $cost;

    /**
     * @ORM\Column(type="string", length=3, nullable=true)
     */
    private $currency;

    /**
     * @ORM\ManyToOne(targetEntity=Structure::class)
     * @ORM\JoinColumn(nullable=false)
     */
    private $structure;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): self
    {
        $this->destination = $destination;

        return $this;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): self
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getCost(): ?string
    {
        return $this->cost;
    }

    public function setCost(string $cost): self
    {
        $this->cost = $cost;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getStructure(): ?Structure
    {
        return $this->structure;
    }

    public function setStructure(?Structure $structure): self
    {
        $this->structure = $structure;

        return $this;
    }
}
