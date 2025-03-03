<?php

namespace App\Controller\Api;

use App\Base\BaseController;
use App\Entity\Structure;
use App\Facade\Trigger\SimpleMessageRequestFacade;
use App\Facade\Trigger\SimpleMessageResponseFacade;
use App\Form\Model\Campaign;
use App\Form\Model\SmsTrigger;
use App\Form\Type\AudienceType;
use App\Manager\CampaignManager;
use App\Manager\PlatformConfigManager;
use App\Manager\VolunteerManager;
use Bundles\ApiBundle\Annotation\Endpoint;
use Bundles\ApiBundle\Annotation\Facade;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Send a message
 *
 * These endpoints are used to send messages to a given phone number.
 *
 * @Route("/api/message", name="api_admin_message_")
 * @IsGranted("ROLE_ADMIN")
 */
class MessageController extends BaseController
{
    /**
     * @var VolunteerManager
     */
    private $volunteerManager;

    /**
     * @var PlatformConfigManager
     */
    private $platformManager;

    /**
     * @var CampaignManager
     */
    private $campaignManager;

    public function __construct(VolunteerManager $volunteerManager,
        PlatformConfigManager $platformManager,
        CampaignManager $campaignManager)
    {
        $this->volunteerManager = $volunteerManager;
        $this->platformManager  = $platformManager;
        $this->campaignManager  = $campaignManager;
    }

    /**
     * Send an SMS to a given structure and its sub structures.
     *
     * @Endpoint(
     *   priority = 700,
     *   request  = @Facade(class = SimpleMessageRequestFacade::class),
     *   response = @Facade(class = SimpleMessageResponseFacade::class)
     * )
     *
     * @Route("/{structureExternalId}/simple-sms", methods={"POST"})
     * @Entity("structure", expr="repository.findOneByExternalIdAndCurrentPlatform(structureExternalId)")
     * @IsGranted("STRUCTURE", subject="structure")
     */
    public function simpleSms(Structure $structure, SimpleMessageRequestFacade $request)
    {
        // User is trying to set trigger's ownership to a volunteer that is out of his/her scope
        $volunteer = $this->volunteerManager->findOneByInternalEmail($request->getSenderInternalEmail());
        if (!$this->isGranted('VOLUNTEER', $volunteer)) {
            throw $this->createNotFoundException();
        }

        $trigger = new SmsTrigger();
        $trigger->setLanguage($this->platformManager->getPlaform($this->getPlatform())->getDefaultLanguage()->getLocale());
        $trigger->setAudience(AudienceType::createEmptyData([
            'structures_global' => [$structure->getId()],
            'badges_all'        => true,
        ]));
        $trigger->setMessage($request->getMessage());

        $campaign        = new Campaign($trigger);
        $campaign->label = sprintf('SimpleSms API (%s)', $this->getUser()->getUserIdentifier());

        $entity = $this->campaignManager->launchNewCampaign($campaign, null, $volunteer);

        return new SimpleMessageResponseFacade(
            count(($entity->getCommunications()[0] ?? [])->getMessages()),
            sprintf('%s%s', getenv('WEBSITE_URL'), $this->generateUrl('communication_index', ['id' => $entity->getId()]))
        );
    }
}