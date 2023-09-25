<?php

namespace Bundles\SandboxBundle\Controller;

use App\Entity\Phone;
use App\Manager\MessageManager;
use App\Manager\VolunteerManager;
use Bundles\SandboxBundle\Base\BaseController;
use Bundles\SandboxBundle\Entity\FakeSms;
use Bundles\SandboxBundle\Manager\FakeSmsManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/fake-sms", name="fake_sms_")
 */
class FakeSmsController extends BaseController
{
    /**
     * @var FakeSmsManager
     */
    private $fakeSmsManager;

    /**
     * @var MessageManager
     */
    private $messageManager;

    /**
     * @var VolunteerManager
     */
    private $volunteerManager;

    /**
     * @param FakeSmsManager   $fakeSmsManager
     * @param MessageManager   $messageManager
     * @param VolunteerManager $volunteerManager
     */
    public function __construct(FakeSmsManager $fakeSmsManager,
        MessageManager $messageManager,
        VolunteerManager $volunteerManager)
    {
        $this->fakeSmsManager   = $fakeSmsManager;
        $this->messageManager   = $messageManager;
        $this->volunteerManager = $volunteerManager;
    }

    /**
     * @Route("/", name="list")
     * @Template()
     */
    public function listAction()
    {
        $phoneNumbers = $this->fakeSmsManager->findAllPhones();
        foreach ($phoneNumbers as $index => $phoneNumber) {
            $phoneNumbers[$index]['volunteer'] = $this->volunteerManager->findOneByPhoneNumber($phoneNumber['phoneNumber']);
        }

        return [
            'phoneNumbers' => $phoneNumbers,
        ];
    }

    /**
     * @Route("/clear/{csrf}", name="clear")
     */
    public function clearAction(string $csrf)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        $this->validateCsrfOrThrowNotFoundException('fake_sms', $csrf);

        $this->fakeSmsManager->truncate();

        return $this->redirectToRoute('sandbox_fake_sms_list');
    }

    /**
     * @Route("/thread/{e164}/{campaignId}", name="thread", defaults={"campaignId"=null})
     * @Template()
     */
    public function threadAction(Phone $phone, ?int $campaignId)
    {
        $volunteer = $phone->getVolunteer();
        $messages  = $this->fakeSmsManager->findMessagesForPhoneNumber($volunteer->getPhoneNumber());

        $lastMessageId = null;
        if ($messages) {
            $lastMessageId = end($messages)->getId();
        }

        return [
            'phoneNumber'   => $volunteer->getPhoneNumber(),
            'volunteer'     => $volunteer,
            'messages'      => $messages,
            'lastMessageId' => $lastMessageId,
            'campaignId'    => $campaignId,
            'phone'         => $phone,
        ];
    }

    /**
     * @Route("/send/{e164}/{csrf}", name="send")
     * @Method("POST")
     */
    public function sendAction(Request $request, Phone $phone, string $csrf)
    {
        $this->validateCsrfOrThrowNotFoundException('fake_sms', $csrf);

        $volunteer = $phone->getVolunteer();

        $body = $request->request->get('message');
        if (!$body) {
            throw $this->createNotFoundException();
        }

        $this->messageManager->handleAnswer($volunteer->getPhoneNumber(), $body);

        $this->fakeSmsManager->save($volunteer, $body, FakeSms::DIRECTION_SENT);

        return new Response();
    }

    /**
     * @Route("/poll/{phoneNumber}", name="poll")
     */
    public function pollAction(Request $request, string $phoneNumber)
    {
        $lastMessageId = $request->request->get('lastMessageId');

        $messages = array_map(function (array $entry) {
            $entry['createdAt'] = $entry['createdAt']->format('d/m/Y H:i');
            $entry['content']   = htmlentities($entry['content']);

            return $entry;
        }, $this->fakeSmsManager->findMessagesHavingIdGreaterThan($phoneNumber, $lastMessageId));

        return new JsonResponse($messages);
    }
}