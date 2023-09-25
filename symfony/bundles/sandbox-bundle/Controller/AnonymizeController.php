<?php

namespace Bundles\SandboxBundle\Controller;

use Bundles\SandboxBundle\Base\BaseController;
use Bundles\SandboxBundle\Manager\AnonymizeManager;
use Symfony\Component\Routing\Annotation\Route;

class AnonymizeController extends BaseController
{
    /**
     * @var AnonymizeManager
     */
    private $anonymizeManager;

    /**
     * @param AnonymizeManager $anonymizeManager
     */
    public function __construct(AnonymizeManager $anonymizeManager)
    {
        $this->anonymizeManager = $anonymizeManager;
    }

    /**
     * @Route("/anonymize/{csrf}", name="anonymize")
     */
    public function anonymizeAction(string $csrf)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        $this->validateCsrfOrThrowNotFoundException('anonymize', $csrf);

        $this->anonymizeManager->anonymizeDatabase();

        return $this->redirectToRoute('sandbox_home');
    }
}