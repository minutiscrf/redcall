<?php

namespace Bundles\SandboxBundle\Controller;

use App\Model\Csrf;
use Bundles\SandboxBundle\Base\BaseController;
use Bundles\SandboxBundle\Manager\FakeOperationManager;
use Bundles\SandboxBundle\Manager\FakeOperationResourceManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/fake-minutis", name="fake_minutis_")
 */
class FakeMinutisController extends BaseController
{
    /**
     * @var FakeOperationManager
     */
    private $operationManager;

    /**
     * @var FakeOperationResourceManager
     */
    private $operationResourceManager;

    public function __construct(FakeOperationManager $operationManager,
        FakeOperationResourceManager $operationResourceManager)
    {
        $this->operationManager         = $operationManager;
        $this->operationResourceManager = $operationResourceManager;
    }

    /**
     * @Route("/{id}", name="list", defaults={"id"=null}, requirements={"id"="\d+"})
     * @Template()
     */
    public function listAction(?int $id)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        return [
            'operations' => $this->operationManager->all(),
            'id'         => $id,
        ];
    }

    /**
     * @Route("/clear/{token}", name="clear")
     */
    public function clear(Csrf $token)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        $this->operationResourceManager->clear();
        $this->operationManager->clear();

        return $this->redirectToRoute('sandbox_fake_minutis_list');
    }
}