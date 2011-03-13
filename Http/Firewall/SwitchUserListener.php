<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Firewall;

use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEventArgs;
use Symfony\Component\HttpKernel\Events;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Role\SwitchUserRole;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Event\SwitchUserEventArgs;
use Symfony\Component\Security\Http\Events;
use Doctrine\Common\EventManager;

/**
 * SwitchUserListener allows a user to impersonate another one temporarily
 * (like the Unix su command).
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class SwitchUserListener implements ListenerInterface
{
    private $securityContext;
    private $provider;
    private $userChecker;
    private $providerKey;
    private $accessDecisionManager;
    private $usernameParameter;
    private $role;
    private $logger;
    private $evm;

    /**
     * Constructor.
     */
    public function __construct(SecurityContextInterface $securityContext, UserProviderInterface $provider, UserCheckerInterface $userChecker, $providerKey, AccessDecisionManagerInterface $accessDecisionManager, LoggerInterface $logger = null, $usernameParameter = '_switch_user', $role = 'ROLE_ALLOWED_TO_SWITCH', EventManager $evm = null)
    {
        if (empty($providerKey)) {
            throw new \InvalidArgumentException('$providerKey must not be empty.');
        }

        $this->securityContext = $securityContext;
        $this->provider = $provider;
        $this->userChecker = $userChecker;
        $this->providerKey = $providerKey;
        $this->accessDecisionManager = $accessDecisionManager;
        $this->usernameParameter = $usernameParameter;
        $this->role = $role;
        $this->logger = $logger;
        $this->evm = $evm;
    }

    /**
     * Handles digest authentication.
     *
     * @param GetResponseEventArgs $eventArgs A GetResponseEventArgs instance
     */
    public function onCoreSecurity(GetResponseEventArgs $eventArgs)
    {
        $request = $eventArgs->getRequest();

        if (!$request->get($this->usernameParameter)) {
            return;
        }

        if ('_exit' === $request->get($this->usernameParameter)) {
            $this->securityContext->setToken($this->attemptExitUser($request));
        } else {
            try {
                $this->securityContext->setToken($this->attemptSwitchUser($request));
            } catch (AuthenticationException $e) {
                if (null !== $this->logger) {
                    $this->logger->debug(sprintf('Switch User failed: "%s"', $e->getMessage()));
                }
            }
        }

        $request->server->set('QUERY_STRING', '');
        $response = new RedirectResponse($request->getUri(), 302);

        $event->setResponse($response);
    }

    /**
     * Attempts to switch to another user.
     *
     * @param Request $request A Request instance
     *
     * @return TokenInterface|null The new TokenInterface if successfully switched, null otherwise
     */
    private function attemptSwitchUser(Request $request)
    {
        $token = $this->securityContext->getToken();
        if (false !== $this->getOriginalToken($token)) {
            throw new \LogicException(sprintf('You are already switched to "%s" user.', $token->getUsername()));
        }

        $this->accessDecisionManager->decide($token, array($this->role));

        $username = $request->get($this->usernameParameter);

        if (null !== $this->logger) {
            $this->logger->debug(sprintf('Attempt to switch to user "%s"', $username));
        }

        $user = $this->provider->loadUserByUsername($username);
        $this->userChecker->checkPostAuth($user);

        $roles = $user->getRoles();
        $roles[] = new SwitchUserRole('ROLE_PREVIOUS_ADMIN', $this->securityContext->getToken());

        $token = new UsernamePasswordToken($user, $user->getPassword(), $this->providerKey, $roles);

        if (null !== $this->evm) {
            $switchEventArgs = new SwitchUserEventArgs($request, $token->getUser());
            $this->evm->dispatchEvent(Events::onSecuritySwitchUser, $switchEventArgs);
        }

        return $token;
    }

    /**
     * Attempts to exit from an already switched user.
     *
     * @param Request $request A Request instance
     *
     * @return TokenInterface The original TokenInterface instance
     */
    private function attemptExitUser(Request $request)
    {
        if (false === $original = $this->getOriginalToken($this->securityContext->getToken())) {
            throw new AuthenticationCredentialsNotFoundException(sprintf('Could not find original Token object.'));
        }

        if (null !== $this->evm) {
            $switchEventArgs = new SwitchUserEventArgs($request, $original->getUser());
            $this->evm->notify(Events::onSecuritySwitchUser, $switchEventArgs);
        }

        return $original;
    }

    /**
     * Gets the original Token from a switched one.
     *
     * @param TokenInterface $token A switched TokenInterface instance
     *
     * @return TokenInterface|false The original TokenInterface instance, false if the current TokenInterface is not switched
     */
    private function getOriginalToken(TokenInterface $token)
    {
        foreach ($token->getRoles() as $role) {
            if ($role instanceof SwitchUserRole) {
                return $role->getSource();
            }
        }

        return false;
    }
}
