<?php

namespace App\Controller\Admin;

use App\Base\BaseController;
use App\Manager\DeletedVolunteerManager;
use EWZ\Bundle\RecaptchaBundle\Form\Type\EWZRecaptchaType;
use EWZ\Bundle\RecaptchaBundle\Validator\Constraints\IsTrue as RecaptchaTrue;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/admin/gdpr", name="admin_gdpr_")
 */
class GdprController extends BaseController
{
    /**
     * @var DeletedVolunteerManager
     */
    private $deletedVolunteerManager;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(DeletedVolunteerManager $deletedVolunteerManager, TranslatorInterface $translator)
    {
        $this->deletedVolunteerManager = $deletedVolunteerManager;
        $this->translator              = $translator;
    }

    /**
     * @Route(name="index")
     * @Template("admin/gdpr/index.html.twig")
     */
    public function index(Request $request) : array
    {
        $form = $this
            ->createFormBuilder()
            ->add('external_id', TextType::class, [
                'label'       => 'admin.gdpr.form.external_id',
                'constraints' => [
                    new NotBlank(),
                    new Callback(function ($data, ExecutionContextInterface $context) {
                        if (!$this->deletedVolunteerManager->isDeleted($data)) {
                            $context->addViolation(
                                $this->translator->trans('admin.gdpr.violations.not_exists')
                            );
                        }
                    }),
                ],
            ])
//            ->add('recaptcha', EWZRecaptchaType::class, [
//                'label'       => 'admin.gdpr.form.captcha',
//                'constraints' => [
//                    new RecaptchaTrue(),
//                ],
//            ])
            ->add('recaptcha', CheckboxType::class, [
                'label'       => 'This field is normally a reCaptcha, replaced by a tick to ease your pentests',
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'admin.gdpr.form.submit',
            ])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->deletedVolunteerManager->undelete(
                $form->get('external_id')->getData()
            );

            $this->addFlash('success', 'admin.gdpr.success');
        }

        return [
            'search' => $form->createView(),
        ];
    }
}