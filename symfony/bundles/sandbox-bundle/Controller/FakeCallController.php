<?php

namespace Bundles\SandboxBundle\Controller;

use App\Entity\Phone;
use App\Manager\VolunteerManager;
use App\Provider\Call\CallProvider;
use Bundles\SandboxBundle\Base\BaseController;
use Bundles\SandboxBundle\Entity\FakeCall;
use Bundles\SandboxBundle\Manager\FakeCallManager;
use Bundles\SandboxBundle\Provider\FakeCallProvider;
use Bundles\TwilioBundle\TwilioEvents;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @Route("/fake-call", name="fake_call_")
 */
class FakeCallController extends BaseController
{
    /**
     * @var FakeCallManager
     */
    private $fakeCallManager;

    /**
     * @var CallProvider
     */
    private $fakeCallProvider;

    /**
     * @var VolunteerManager
     */
    private $volunteerManager;

    /**
     * @param FakeCallManager  $fakeCallManager
     * @param FakeCallProvider $fakeCallProvider
     * @param VolunteerManager $volunteerManager
     */
    public function __construct(FakeCallManager $fakeCallManager,
        FakeCallProvider $fakeCallProvider,
        VolunteerManager $volunteerManager)
    {
        $this->fakeCallManager  = $fakeCallManager;
        $this->fakeCallProvider = $fakeCallProvider;
        $this->volunteerManager = $volunteerManager;
    }

    /**
     * @Route("/", name="list")
     * @Template()
     */
    public function listAction()
    {
        $phones = $this->fakeCallManager->findAllPhones();
        foreach ($phones as $index => $phone) {
            $phones[$index]['volunteer'] = $this->volunteerManager->findOneByPhoneNumber($phone['phoneNumber']);
        }

        return [
            'phones' => $phones,
        ];
    }

    /**
     * @Route("/clear/{csrf}", name="clear")
     */
    public function clearAction(string $csrf)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        $this->validateCsrfOrThrowNotFoundException('fake_call', $csrf);

        $this->fakeCallManager->truncate();

        return $this->redirectToRoute('sandbox_fake_call_list');
    }

    /**
     * @Route("/read/{e164}/{campaignId}", name="read", defaults={"campaignId"=null})
     * @Template()
     */
    public function readAction(Request $request, Phone $phone, ?int $campaignId)
    {
        $volunteer = $phone->getVolunteer();

        $messages = $this->fakeCallManager->findMessagesForPhone($volunteer->getPhoneNumber());

        $form = $this->createFormBuilder()
                     ->add('digit', NumberType::class, [
                         'label'       => 'sandbox.fake_call.your_message',
                         'constraints' => [
                             new NotBlank(),
                             new Length(['min' => 1, 'max' => 1]),
                         ],
                     ])
                     ->add('submit', SubmitType::class, [
                         'label' => 'sandbox.fake_call.send',
                     ])
                     ->getForm()
                     ->handleRequest($request);

        if ($messages && $form->isSubmitted() && $form->isValid()) {
            /** @var FakeCall $last */
            $last = reset($messages);

            $this->fakeCallProvider->triggerHook(
                $last->getPhoneNumber(),
                $last->getPhoneNumber(),
                ['message_id' => $last->getMessageId()],
                TwilioEvents::CALL_KEY_PRESSED,
                FakeCall::TYPE_KEY_PRESS,
                $form->get('digit')->getData()
            );

            return $this->redirectToRoute('sandbox_fake_call_read', [
                'e164'       => $phone->getE164(),
                'campaignId' => $campaignId,
            ]);
        }

        return [
            'email'      => $volunteer->getEmail(),
            'volunteer'  => $volunteer,
            'messages'   => $messages,
            'campaignId' => $campaignId,
            'form'       => $form->createView(),
            'phone'      => $phone,
        ];
    }
}