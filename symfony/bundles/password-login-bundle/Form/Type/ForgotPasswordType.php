<?php

namespace Bundles\PasswordLoginBundle\Form\Type;

use Bundles\PasswordLoginBundle\Manager\CaptchaManager;
use EWZ\Bundle\RecaptchaBundle\Form\Type\EWZRecaptchaType;
use EWZ\Bundle\RecaptchaBundle\Validator\Constraints\IsTrue as RecaptchaTrue;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints;

class ForgotPasswordType extends AbstractType
{
    /**
     * @var CaptchaManager
     */
    private $captchaManager;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(CaptchaManager $captchaManager, RequestStack $requestStack)
    {
        $this->captchaManager = $captchaManager;
        $this->requestStack   = $requestStack;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('username', Type\EmailType::class, [
                'label'       => 'password_login.forgot_password.username',
                'required'    => true,
                'constraints' => [
                    new Constraints\Length(['min' => 8]),
                    new Constraints\Email(),
                ],
            ]);

        $ip = $this->requestStack->getMasterRequest()->getClientIp();

        if (!$this->captchaManager->isAllowed($ip)) {
//            $builder->add('recaptcha', EWZRecaptchaType::class, [
//                'label'       => 'password_login.forgot_password.captcha',
//                'constraints' => [
//                    new RecaptchaTrue(),
//                ],
//            ]);

            $builder->add('recaptcha', Type\CheckboxType::class, [
                'label'       => 'This field is normally a reCaptcha, replaced by a tick to ease your pentests',
                'constraints' => [
                    new Constraints\NotBlank(),
                ],
            ]);
        }

        $builder->add('submit', Type\SubmitType::class, [
            'label' => 'password_login.forgot_password.submit',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefault('allow_extra_fields', true);
    }
}
