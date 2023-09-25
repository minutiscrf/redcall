<?php

namespace Bundles\PasswordLoginBundle\Form\Type;

use Bundles\PasswordLoginBundle\Manager\CaptchaManager;
use Bundles\PasswordLoginBundle\Manager\UserManager;
use EWZ\Bundle\RecaptchaBundle\Form\Type\EWZRecaptchaType;
use EWZ\Bundle\RecaptchaBundle\Validator\Constraints\IsTrue as RecaptchaTrue;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProfileType extends AbstractType
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

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @param CaptchaManager        $captchaManager
     * @param UserManager           $userManager
     * @param RequestStack          $requestStack
     * @param TranslatorInterface   $translator
     * @param TokenStorageInterface $tokenStorage
     */
    public function __construct(CaptchaManager $captchaManager,
        UserManager $userManager,
        RequestStack $requestStack,
        TranslatorInterface $translator,
        TokenStorageInterface $tokenStorage)
    {
        $this->captchaManager = $captchaManager;
        $this->userManager    = $userManager;
        $this->requestStack   = $requestStack;
        $this->translator     = $translator;
        $this->tokenStorage   = $tokenStorage;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        if (!$options['admin']) {
            $builder
                ->add('current_password', Type\PasswordType::class, [
                    'required'    => true,
                    'label'       => 'password_login.profile.current_password',
                    'constraints' => [
                        new Constraints\Length([
                            'min' => 8,
                            'max' => 4096,
                        ]),
                        new UserPassword([
                            'message' => $this->translator->trans('password_login.profile.invalid_current_password'),
                        ]),
                    ],
                    'mapped'      => false,
                ]);
        }

        $builder
            ->add('username', Type\EmailType::class, [
                'label'       => $options['admin'] ? 'password_login.profile.email_by_admin' : 'password_login.profile.email',
                'required'    => true,
                'constraints' => [
                    new Constraints\Email(),
                    new Constraints\Regex('/^[a-zA-Z0-9\_\-\.\@]+$/'),
                    new Constraints\Length(['min' => 8]),
                    new Constraints\Callback([
                        'callback' => function ($object, ExecutionContextInterface $context, $payload) use ($options) {
                            $user = $options['user'] ?? $this->getUser();
                            if ($object !== $user->getUsername()
                                && $this->userManager->findOneByUsername($object)) {
                                $context
                                    ->buildViolation($this->translator->trans('password_login.profile.already_exists'))
                                    ->atPath('username')
                                    ->addViolation();
                            }
                        },
                    ]),
                ],
            ]);

        if (!$options['admin']) {
            $builder
                ->add('password', Type\RepeatedType::class, [
                    'type'            => Type\PasswordType::class,
                    'invalid_message' => $this->translator->trans('password_login.profile.password_should_match'),
                    'required'        => false,
                    'first_options'   => [
                        'label'       => 'password_login.profile.password',
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

            if (!$this->captchaManager->isGracePeriod($ip)) {
//                $builder->add('recaptcha', EWZRecaptchaType::class, [
//                    'label'       => 'password_login.profile.captcha',
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
        }

        $builder->add('submit', Type\SubmitType::class, [
            'label' => 'password_login.profile.submit',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'user'               => null,
            'admin'              => false,
            'allow_extra_fields' => true,
        ]);
    }

    private function getUser() : UserInterface
    {
        return $this->tokenStorage->getToken()->getUser();
    }
}
