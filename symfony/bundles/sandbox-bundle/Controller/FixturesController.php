<?php

namespace Bundles\SandboxBundle\Controller;

use App\Form\Type\StructureWidgetType;
use Bundles\SandboxBundle\Base\BaseController;
use Bundles\SandboxBundle\Manager\FixturesManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/fixtures", name="fixtures_")
 */
class FixturesController extends BaseController
{
    /**
     * @var FixturesManager
     */
    private $fixturesManager;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(FixturesManager $fixturesManager,
        TranslatorInterface $translator)
    {
        $this->fixturesManager = $fixturesManager;
        $this->translator      = $translator;
    }

    /**
     * @Route(path="/", name="index")
     * @Template()
     */
    public function index(Request $request)
    {
        throw $this->createNotFoundException('disabled for the hackathon');

        $structure = $this->getStructureForm($request);
        if ($structure->isSubmitted() && $structure->isValid()) {
            $this->fixturesManager->createStructure(
                $this->getPlatform(),
                $structure->get('name')->getData(),
                $structure->get('parent')->getData(),
                $structure->get('number_volunteers')->getData(),
                $structure->get('bind_to_user')->getData()
            );

            $this->addFlash('success', $this->translator->trans('sandbox.fixtures.structure.created'));

            return $this->redirectToRoute('sandbox_fixtures_index');
        }

        $volunteer = $this->getVolunteerForm($request);
        if ($volunteer->isSubmitted() && $volunteer->isValid()) {
            $this->fixturesManager->createVolunteers(
                $n = $volunteer->get('number_volunteers')->getData(),
                $volunteer->get('structure')->getData()
            );

            $this->addFlash('success', $this->translator->trans('sandbox.fixtures.volunteer.created', [
                ' % nbr % ' => $n,
            ]));

            return $this->redirectToRoute('sandbox_fixtures_index');
        }

        return [
            'structure' => $structure->createView(),
            'volunteer' => $volunteer->createView(),
        ];
    }

    private function getStructureForm(Request $request) : FormInterface
    {
        return $this->createFormBuilder()
                    ->add('name', TextType::class, [
                        'label'       => 'sandbox.fixtures.structure.name',
                        'constraints' => [
                            new NotBlank(),
                            new Length(['min' => 3]),
                        ],
                    ])
                    ->add('parent', StructureWidgetType::class, [
                        'required' => false,
                        'label'    => 'sandbox.fixtures.structure.parent',
                    ])
                    ->add('number_volunteers', NumberType::class, [
                        'label'       => 'sandbox.fixtures.structure.number_volunteers',
                        'constraints' => [
                            new NotBlank(),
                            new Length(['min' => 0]),
                        ],
                    ])
                    ->add('bind_to_user', CheckboxType::class, [
                        'label'    => 'sandbox.fixtures.structure.bind_to_user',
                        'required' => false,
                    ])
                    ->add('submit', SubmitType::class, [
                        'label' => 'base.button.create',
                    ])
                    ->getForm()
                    ->handleRequest($request);
    }

    private function getVolunteerForm(Request $request) : FormInterface
    {
        return $this->createFormBuilder()
                    ->add('number_volunteers', NumberType::class, [
                        'label'       => 'sandbox.fixtures.volunteer.number_volunteers',
                        'constraints' => [
                            new NotBlank(),
                            new Length(['min' => 0]),
                        ],
                    ])
                    ->add('structure', StructureWidgetType::class, [
                        'label'    => false,
                        'required' => false,
                    ])
                    ->add('submit', SubmitType::class, [
                        'label' => 'base.button.create',
                    ])
                    ->getForm()
                    ->handleRequest($request);
    }
}