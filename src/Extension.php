<?php

declare(strict_types=1);

namespace Modulento\Faq;

use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Frequently asked questions, managed in the administration. Each entry is
 * either general or assigned to one installed extension, and only shows
 * while that extension is enabled - so an operator who runs one
 * marketplace extension after another is never shown another one's
 * questions on the same /faq page.
 */
final class Extension implements ExtensionContract
{
    public const PERMISSION = 'faq.items.manage';

    public function register(Registrar $registrar): void
    {
        $registrar->permission(self::PERMISSION, 'faq.permission.items_manage');

        $registrar->routes(function (Router $router): void {
            $router->get('/faq', [FaqController::class, 'index'], Router::PUBLIC);

            $router->get('/admin/faq', [AdminController::class, 'index'], self::PERMISSION);
            $router->get('/admin/faq/new', [AdminController::class, 'edit'], self::PERMISSION);
            $router->post('/admin/faq/new', [AdminController::class, 'save'], self::PERMISSION);
            $router->get('/admin/faq/{id}', [AdminController::class, 'edit'], self::PERMISSION);
            $router->post('/admin/faq/{id}', [AdminController::class, 'save'], self::PERMISSION);
            $router->post('/admin/faq/{id}/delete', [AdminController::class, 'delete'], self::PERMISSION);
        });

        $registrar->adminMenu('faq.admin.menu', '/admin/faq', self::PERMISSION, 'content');
        $registrar->footerLink('faq.footer_link', '/faq');
    }
}
