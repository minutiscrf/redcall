<?php

namespace Bundles\PasswordLoginBundle\Form\Type;

use Bundles\PasswordLoginBundle\Base\BaseType;
use Bundles\PasswordLoginBundle\Manager\CaptchaManager;
use Bundles\PasswordLoginBundle\Manager\UserManager;
use EWZ\Bundle\RecaptchaBundle\Form\Type\EWZRecaptchaType;
use EWZ\Bundle\RecaptchaBundle\Validator\Constraints\IsTrue as RecaptchaTrue;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationType extends AbstractType
{
    /**
     * @var CaptchaManager
     */
    private $captchaManager;

    /**
     * @var UserManager
     */
    private $userManager;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(CaptchaManager $captchaManager,
        UserManager $userManager,
        RequestStack $requestStack,
        TranslatorInterface $translator)
    {
        $this->captchaManager = $captchaManager;
        $this->userManager    = $userManager;
        $this->requestStack   = $requestStack;
        $this->translator     = $translator;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('username', Type\EmailType::class, [
                'label'       => 'password_login.register.email',
                'required'    => true,
                'constraints' => [
                    new Constraints\Email(),
                    new Constraints\Regex('/^[a-zA-Z0-9\_\-\.\@]+$/'),
                    new Constraints\Length(['min' => 8]),
                    new Constraints\Callback([
                        'callback' => function ($object, ExecutionContextInterface $context, $payload) {
                            if ($this->userManager->findOneByUsername($object)) {
                                $context
                                    ->buildViolation($this->translator->trans('password_login.register.already_exists'))
                                    ->atPath('username')
                                    ->addViolation();
                            }
                        },
                    ]),
                ],
            ])
            ->add('password', Type\RepeatedType::class, [
                'type'            => Type\PasswordType::class,
                'invalid_message' => $this->translator->trans('password_login.register.password_should_match'),
                'required'        => true,
                'first_options'   => [
                    'label'       => 'password_login.register.password',
                    'constraints' => [
                        new Constraints\Length([
                            'min' => 8,
                            'max' => 4096,
                        ]),
                        new Constraints\NotCompromisedPassword(),
                    ],
                ],
                'second_options'  => ['label' => 'password_login.register.repeat_password'],
            ]);

        $ip = $this->requestStack->getMasterRequest()->getClientIp();

        if (!$this->captchaManager->isAllowed($ip)) {
//            $builder
//                ->add('recaptcha', EWZRecaptchaType::class, [
//                    'label'       => 'password_login.register.captcha',
//                    'constraints' => [
//                        new RecaptchaTrue(),
//                    ],
//                    'mapped'      => false,
//                ]);

            $builder->add('recaptcha', Type\CheckboxType::class, [
                'label'       => 'This field is normally a reCaptcha, replaced by a tick to ease your pentests',
                'constraints' => [
                    new Constraints\NotBlank(),
                ],
            ]);
        }

        $builder->add('submit', Type\SubmitType::class, [
            'label' => 'password_login.register.register',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefault('allow_extra_fields', true);
    }
}
