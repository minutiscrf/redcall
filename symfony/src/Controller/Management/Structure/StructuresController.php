<?php

namespace App\Controller\Management\Structure;

use App\Base\BaseController;
use App\Component\HttpFoundation\ArrayToCsvResponse;
use App\Entity\Structure;
use App\Entity\Volunteer;
use App\Enum\Platform;
use App\Form\Type\StructureType;
use App\Manager\PlatformConfigManager;
use App\Manager\StructureManager;
use App\Manager\UserManager;
use App\Model\Csrf;
use App\Model\PlatformConfig;
use Bundles\PaginationBundle\Manager\PaginationManager;
use App\Entity\Pegass;
use App\Manager\PegassManager;
use Ramsey\Uuid\Uuid;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route(path="management/structures", name="management_structures_")
 */
class StructuresController extends BaseController
{
    /**
     * @var StructureManager
     */
    private $structureManager;

    /**
     * @var PaginationManager
     */
    private $paginationManager;

    /**
     * @var PegassManager
     */
    private $pegassManager;

    /**
     * @var UserManager
     */
    private $userManager;

    /**
     * @var PlatformConfigManager
     */
    private $platformManager;

    /**
     * @var KernelInterface
     */
    private $kernel;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(StructureManager $structureManager,
        PaginationManager $paginationManager,
        PegassManager $pegassManager,
        UserManager $userManager,
        PlatformConfigManager $platformManager,
        KernelInterface $kernel,
        TranslatorInterface $translator)
    {
        $this->structureManager  = $structureManager;
        $this->paginationManager = $paginationManager;
        $this->pegassManager     = $pegassManager;
        $this->userManager       = $userManager;
        $this->platformManager   = $platformManager;
        $this->kernel            = $kernel;
        $this->translator        = $translator;
    }

    /**
     * @Route("/{enabled}", name="list", defaults={"enabled" = true}, requirements={"enabled" = "^\d?$"})
     */
    public function listAction(Request $request, bool $enabled)
    {
        $search = $this->createSearchForm($request);

        $criteria = null;
        if ($search->isSubmitted() && $search->isValid()) {
            $criteria = $search->get('criteria')->getData();
            $enabled  = $search->get('only_enabled')->getData();
        }

        $queryBuilder = $this->structureManager->searchQueryBuilder($criteria, $enabled);

        $redcallUsers = $this->structureManager->countRedCallUsersInPager(
            $this->paginationManager->getPager(
                $this->structureManager->countRedCallUsersQueryBuilder($this->getPlatform(), $queryBuilder)
            )
        );

        return $this->render('management/structures/list.html.twig', [
            'search'       => $search->createView(),
            'structures'   => $this->paginationManager->getPager($queryBuilder),
            'redcallUsers' => $redcallUsers,
            'enabled'      => $enabled,
            'platforms'    => $this->getPlatforms(),
        ]);
    }

    /**
     * @Route("/create/{id}", name="create", defaults={"id" = null})
     * @Security("is_granted('ROLE_ADMIN')")
     * @Template("management/structures/form.html.twig")
     */
    public function createStructure(Request $request, ?Structure $structure = null)
    {
        if (null === $structure) {
            $structure = new Structure();
            $structure->setExternalId(Uuid::uuid4());
            $structure->setPlatform($this->getPlatform());
        }

        $form = $this->createForm(StructureType::class, $structure);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->structureManager->save($structure);

            return $this->redirectToRoute('management_structures_list');
        }

        return [
            'structure' => $structure,
            'form'      => $form->createView(),
        ];
    }

    /**
     * @Route(name="pegass", path="/pegass/{id}")
     * @IsGranted("ROLE_ADMIN")
     */
    public function pegass(Structure $structure)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        if (Platform::FR !== $structure->getPlatform()) {
            throw $this->createNotFoundException();
        }

        $entity = $this->pegassManager->getEntity(Pegass::TYPE_STRUCTURE, $structure->getExternalId(), false);
        if (!$entity) {
            throw $this->createNotFoundException();
        }

        return $this->render('management/structures/pegass.html.twig', [
            'structure' => $structure,
            'pegass'    => json_encode($entity->getContent(), JSON_PRETTY_PRINT),
            'entity'    => $entity,
        ]);
    }

    /**
     * @Route(name="export", path="/export/{id}")
     * @IsGranted("STRUCTURE", subject="structure")
     */
    public function export(Structure $structure)
    {
        $rows = [];
        foreach ($structure->getVolunteers() as $volunteer) {
            /** @var Volunteer $volunteer */
            $rows[] = [
                'externalId' => $volunteer->getExternalId(),
                'firstname'  => $volunteer->getFirstName(),
                'lastname'   => $volunteer->getLastName(),
                'phone'      => $volunteer->getPhoneNumber() ?: null,
                'email'      => $volunteer->getEmail(),
            ];
        }

        return new ArrayToCsvResponse($rows, sprintf('%s.%s.csv', date('Y-m-d'), $structure->getName()));
    }

    /**
     * @Route(name="list_users", path="/list-users")
     */
    public function listUsers(Request $request)
    {
        $structure = $this->getStructureById($request->get('id'));
        $users     = $this->userManager->getRedCallUsersInStructure($structure);

        return $this->json([
            'title' => $this->translator->trans('manage_structures.redcall_users', [
                '%name%' => $structure->getName(),
            ]),
            'body'  => $this->renderView('management/structures/users.html.twig', [
                'users' => $users,
            ]),
        ]);
    }

    /**
     * @Route(path="/toggle-lock-{id}/{token}", name="toggle_lock")
     * @IsGranted("STRUCTURE", subject="structure")
     * @IsGranted("ROLE_ADMIN")
     * @Template("management/structures/structure.html.twig")
     */
    public function toggleLock(Structure $structure, Csrf $token)
    {
        $structure->setLocked(1 - $structure->isLocked());

        $this->structureManager->save($structure);

        return $this->getContext($structure);
    }

    /**
     * @Route(path="/toggle-enable-{id}/{token}", name="toggle_enable")
     * @IsGranted("STRUCTURE", subject="structure")
     * @IsGranted("ROLE_ADMIN")
     * @Template("management/structures/structure.html.twig")
     */
    public function toggleEnable(Structure $structure, Csrf $token)
    {
        $structure->setEnabled(1 - $structure->isEnabled());

        $this->structureManager->save($structure);

        return $this->getContext($structure);
    }

    /**
     * @Route(name="update_platform", path="/change-platform/{csrf}/{id}/{platform}")
     * @IsGranted("ROLE_ROOT")
     * @IsGranted("STRUCTURE", subject="structure")
     */
    public function changePlatform(Structure $structure, Csrf $csrf, PlatformConfig $platform)
    {
        $structure->setPlatform($platform);

        $this->structureManager->save($structure);

        return $this->redirectToRoute('management_structures_list');
    }

    private function getContext(Structure $structure)
    {
        return [
            'platforms'    => $this->getPlatforms(),
            'structure'    => $structure,
            'redcallUsers' => [
                $structure->getId() => count($structure->getUsers()),
            ],
        ];
    }

    private function createSearchForm(Request $request) : FormInterface
    {
        return $this->createFormBuilder(['only_enabled' => true], ['csrf_protection' => false])
                    ->setMethod('GET')
                    ->add('criteria', TextType::class, [
                        'label'    => 'manage_structures.search.label',
                        'required' => false,
                    ])
                    ->add('only_enabled', CheckboxType::class, [
                        'label'    => 'manage_structures.search.only_enabled',
                        'required' => false,
                    ])
                    ->add('submit', SubmitType::class, [
                        'label' => 'manage_structures.search.button',
                    ])
                    ->getForm()
                    ->handleRequest($request);
    }

    private function getStructureById(?int $id) : Structure
    {
        $structure = $this->structureManager->find($id);

        if (null === $id) {
            throw $this->createNotFoundException();
        }

        if (!$this->isGranted('STRUCTURE', $structure)) {
            throw $this->createAccessDeniedException();
        }

        return $structure;
    }

    private function getPlatforms() : ?array
    {
        if (!$this->getUser()->isRoot()) {
            return null;
        }

        return $this->platformManager->getAvailablePlatforms();
    }
}
