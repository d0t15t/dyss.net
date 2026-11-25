<?php

declare(strict_types=1);

namespace Drupal\ibase_private\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Login gate and noindex header for the private ibase site.
 *
 * Permissions already deny anonymous users everything; this gate also covers
 * routes that need no permission at all (e.g. sitemap.xml, contrib pages) and
 * whatever a future module adds, so nothing but the login and password reset
 * pages is ever rendered for an anonymous visitor.
 */
class PrivateSiteSubscriber implements EventSubscriberInterface {

  /**
   * Routes anonymous visitors may still reach.
   */
  protected const ALLOWED_ROUTES = [
    'user.login',
    'user.pass',
    'user.reset',
    'user.reset.form',
    'user.reset.login',
    // Aggregated CSS/JS for the login page, and keyed cron runs.
    'system.css_asset',
    'system.js_asset',
    'system.cron',
  ];

  public function __construct(
    protected AccountInterface $currentUser,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After routing (32), the authentication filter (31) and maintenance
    // mode (30), so the route and the user are known.
    $events[KernelEvents::REQUEST][] = ['onRequest', 29];
    // Routes with an access check fail during routing, before onRequest()
    // runs; turn those 403s into the login redirect too. Before core's
    // custom/default 403 page subscribers (-50, -128).
    $events[KernelEvents::EXCEPTION][] = ['onAccessDenied', 0];
    $events[KernelEvents::RESPONSE][] = ['onResponse', -100];
    return $events;
  }

  /**
   * Redirects anonymous visitors to the login page instead of a 403 or 404.
   *
   * 404s are redirected too, so an anonymous visitor can't tell which paths
   * exist.
   */
  public function onAccessDenied(ExceptionEvent $event): void {
    $exception = $event->getThrowable();
    if (!($exception instanceof AccessDeniedHttpException || $exception instanceof NotFoundHttpException) || $this->currentUser->isAuthenticated()) {
      return;
    }
    $request = $event->getRequest();
    $route = $request->attributes->get('_route');
    if ($request->getRequestFormat() !== 'html' || in_array($route, ['system.files', 'system.private_file_download', 'image.style_private'], TRUE)) {
      return;
    }
    $event->setResponse($this->loginRedirect($request->getRequestUri()));
  }

  /**
   * Builds an uncacheable redirect to the login form.
   */
  protected function loginRedirect(string $destination): RedirectResponse {
    $login = Url::fromRoute('user.login', [], ['query' => ['destination' => $destination]]);
    $response = new RedirectResponse($login->toString(), Response::HTTP_FOUND);
    $response->headers->set('Cache-Control', 'no-store');
    return $response;
  }

  /**
   * Sends anonymous visitors to the login page.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->currentUser->isAuthenticated()) {
      return;
    }
    if (in_array($this->routeMatch->getRouteName(), self::ALLOWED_ROUTES, TRUE)) {
      return;
    }
    $request = $event->getRequest();
    // Non-HTML requests (files, JSON, feeds) get a plain 403, not a redirect.
    if ($request->getRequestFormat() !== 'html' || in_array($this->routeMatch->getRouteName(), ['system.files', 'system.private_file_download', 'image.style_private'], TRUE)) {
      $event->setResponse(new Response('Access denied', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']));
      return;
    }
    $event->setResponse($this->loginRedirect($request->getRequestUri()));
  }

  /**
   * Asks search engines not to index or follow anything on this site.
   */
  public function onResponse(ResponseEvent $event): void {
    $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
  }

}
