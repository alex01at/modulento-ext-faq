<?php

declare(strict_types=1);

namespace Modulento\Faq;

use Modulento\Core\Controller\Controller;

final class FaqController extends Controller
{
    public function index(array $params): void
    {
        $app = $this->app;
        $locale = $app->translator->locale();
        $search = trim((string) ($_GET['q'] ?? ''));

        $this->render('@faq/index.twig', [
            'items' => (new Faq($app->db))->listVisible($locale, $app->locales->default(), $app->extensions->enabledIds(), $search),
            'search' => $search,
        ]);
    }
}
