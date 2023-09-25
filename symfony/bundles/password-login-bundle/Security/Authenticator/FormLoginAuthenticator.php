<?php

namespace Bundles\PasswordLoginBundle\Security\Authenticator;

use Bundles\PasswordLoginBundle\Entity\AbstractUser;
use Bundles\PasswordLoginBundle\Form\Type\ConnectType;
use Bundles\PasswordLoginBundle\Manager\CaptchaManager;
use Bundles\PasswordLoginBundle\Traits\ServiceTrait;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator;
use Symfony\Contracts\Translation\TranslatorInterface;

class FormLoginAuthenticator extends AbstractFormLoginAuthenticator
{
    /**
     * @var CaptchaManager
     */
    private $captchaManager;

    /**
     * @var FormFactoryInterface
     */
    private $formFactory;

    /**
     * @var UserPasswordEncoderInterface
     */
    private $encoder;

    /**
     * @var Session
     */
    private $session;

    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var string
     */
    private $homeRoute;

    public function __construct(CaptchaManager $captchaManager,
        FormFactoryInterface $formFactory,
        UserPasswordEncoderInterface $encoder,
        Session $session,
        TokenStorageInterface $tokenStorage,
        TranslatorInterface $translator,
        RequestStack $requestStack,
        RouterInterface $router,
        string $homeRoute)
    {
        $this->captchaManager = $captchaManager;
        $this->formFactory    = $formFactory;
        $this->encoder        = $encoder;
        $this->session        = $session;
        $this->tokenStorage   = $tokenStorage;
        $this->translator     = $translator;
        $this->requestStack   = $requestStack;
        $this->router         = $router;
        $this->homeRoute      = $homeRoute;
    }

    public function start(Request $request, AuthenticationException $authException = null)
    {
        $this->session->set('auth_redirect', [
            'route'        => $request->attributes->get('_route'),
            'route_params' => $request->attributes->get('_route_params'),
        ]);

        return new RedirectResponse($this->getLoginUrl());
    }

    public function supports(Request $request)
    {
        if ('/connect' !== $request->getPathInfo()) {
            return false;
        }

        $connectForm = $this
            ->formFactory
            ->create(ConnectType::class)
            ->handleRequest($request);

        if (!$connectForm->isSubmitted()) {
            return false;
        }

        if (!$connectForm->isValid()) {
            $this->decreaseGrace();

            return false;
        }

        return true;
    }

    public function getCredentials(Request $request)
    {
        $connectForm = $this
            ->formFactory
            ->create(ConnectType::class)
            ->handleRequest($request);

        $data = $connectForm->getData();

        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        return $userProvider->loadUserByUsername($credentials['username']);
    }

    public function checkCredentials($credentials, UserInterface $user)
    {
        $isValid = $this
            ->encoder
            ->isPasswordValid($user, $credentials['password']);

        if (!$isValid) {
            $this->decreaseGrace();

            throw new BadCredentialsException();
        }

        return true;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        /**
         * @var AbstractUser $user
         */
        $user = $token->getUser();

        if (!$user->isVerified()) {
            $this->session->getFlashBag()->add('alert', $this->translator->trans('password_login.verify_email.failure'));
            $this->tokenStorage->setToken();

            return new RedirectResponse($this->getLoginUrl());
        }

        if ($user->isTrusted()) {
            $ip = $this
                ->requestStack
                ->getMasterRequest()
                ->getClientIp();

            $this
                ->captchaManager
                ->whitelistNow($ip);
        }

        $route = $this->session->get('auth_redirect', [
            'route'        => $this->homeRoute,
            'route_params' => [],
        ]);

        $this->session->remove('auth_redirect');

        $response = new RedirectResponse(
            $this->router->generate($route['route'], $route['route_params'])
        );

        $response->headers->setCookie(
            new Cookie('auth_method', 'password_login', strtotime('Sat, 10-Jul-2100 06:37:00 +0200'))
        );

        return $response;
    }

    protected function getLoginUrl()
    {
        return $this->router->generate('password_login_connect');
    }

    private function decreaseGrace()
    {
        $this->captchaManager->decreaseGrace(
            $this->requestStack->getMainRequest()->getClientIp()
        );
    }
}