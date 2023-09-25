<?php

namespace Bundles\SandboxBundle\Controller;

use App\Entity\Volunteer;
use App\Manager\VolunteerManager;
use Bundles\SandboxBundle\Base\BaseController;
use Bundles\SandboxBundle\Manager\FakeEmailManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/fake-email", name="fake_email_")
 */
class FakeEmailController extends BaseController
{
    /**
     * @var FakeEmailManager
     */
    private $fakeEmailManager;

    /**
     * @var VolunteerManager
     */
    private $volunteerManager;

    /**
     * @param FakeEmailManager $fakeEmailManager
     * @param VolunteerManager $volunteerManager
     */
    public function __construct(FakeEmailManager $fakeEmailManager, VolunteerManager $volunteerManager)
    {
        $this->fakeEmailManager = $fakeEmailManager;
        $this->volunteerManager = $volunteerManager;
    }

    /**
     * @Route("/", name="list")
     * @Template()
     */
    public function listAction()
    {
        $emails = $this->fakeEmailManager->findAllEmails();
        foreach ($emails as $index => $email) {
            $emails[$index]['volunteer'] = $this->volunteerManager->findOneByEmail($email['email']);
        }

        return [
            'emails' => $emails,
        ];
    }

    /**
     * @Route("/clear/{csrf}", name="clear")
     */
    public function clearAction(string $csrf)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        $this->validateCsrfOrThrowNotFoundException('fake_email', $csrf);

        $this->fakeEmailManager->truncate();

        return $this->redirectToRoute('sandbox_fake_email_list');
    }

    /**
     * @Route("/read/{email}/{campaignId}", name="read", defaults={"campaignId"=null})
     * @Template()
     */
    public function readAction(Volunteer $volunteer, ?int $campaignId)
    {
        $messages = $this->fakeEmailManager->findMessagesForEmail($volunteer->getEmail());

        $lastMessageId = null;
        if ($messages) {
            $lastMessageId = end($messages)->getId();
        }

        return [
            'email'         => $volunteer->getEmail(),
            'volunteer'     => $volunteer,
            'messages'      => $messages,
            'lastMessageId' => $lastMessageId,
            'campaignId'    => $campaignId,
        ];
    }
}