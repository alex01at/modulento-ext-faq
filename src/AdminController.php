<?php

declare(strict_types=1);

namespace Modulento\Faq;

use Modulento\Core\Support\Session;

final class AdminController extends \Modulento\Core\Controller\Controller
{
    private const PER_PAGE = 50;

    public function index(array $params): void
    {
        $app = $this->app;
        $filter = (string) ($_GET['extension'] ?? 'all');
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $faq = new Faq($app->db);
        $list = $faq->listAll($filter === 'all' ? null : $filter, $page, self::PER_PAGE);
        $locale = $app->translator->locale();

        $this->render('@faq/admin/index.twig', [
            'items' => array_map(fn (array $item) => $item + [
                'question' => $item['texts'][$locale]['question'] ?? reset($item['texts'])['question'] ?? '',
            ], $list['rows']),
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'filter' => $filter,
            'extensions' => $this->extensionChoices(),
        ]);
    }

    public function edit(array $params): void
    {
        $item = isset($params['id']) ? (new Faq($this->app->db))->find((int) $params['id']) : null;
        if (isset($params['id']) && $item === null) {
            $this->notFound();
            return;
        }

        $this->renderForm($item);
    }

    public function save(array $params): void
    {
        $id = isset($params['id']) ? (int) $params['id'] : null;
        if ($id !== null && (new Faq($this->app->db))->find($id) === null) {
            $this->notFound();
            return;
        }

        $extensionId = (string) ($_POST['extension_id'] ?? '');
        $extensionId = $extensionId !== '' && isset($this->extensionChoices()[$extensionId]) ? $extensionId : null;
        $position = max(0, min(9999, (int) ($_POST['position'] ?? 0)));
        $texts = is_array($_POST['texts'] ?? null) ? $_POST['texts'] : [];

        $result = (new Faq($this->app->db))->save($id, $extensionId, $position, $texts);
        if ($result['errors'] !== []) {
            $this->renderForm($id !== null ? ['id' => $id, 'extension_id' => $extensionId, 'position' => $position, 'texts' => $texts] : null, $result['errors'], $texts);
            return;
        }

        Session::flash('success', $this->trans('faq.admin.saved'));
        $this->redirect('/admin/faq');
    }

    public function delete(array $params): void
    {
        (new Faq($this->app->db))->delete((int) $params['id']);
        Session::flash('success', $this->trans('faq.admin.deleted'));
        $this->redirect('/admin/faq');
    }

    /** @param ?array<string, mixed> $typedTexts the form as sent, after a failed validation */
    private function renderForm(?array $item, array $errors = [], ?array $typedTexts = null): void
    {
        $this->render('@faq/admin/edit.twig', [
            'item' => $item,
            'texts' => $typedTexts ?? ($item['texts'] ?? []),
            'errors' => $errors,
            'locales' => $this->app->locales->enabled(),
            'extensions' => $this->extensionChoices(),
        ]);
    }

    /** @return array<string, string> extension id => name, every discovered extension but this one, sorted */
    private function extensionChoices(): array
    {
        $choices = [];
        foreach ($this->app->extensions->discover() as $id => $manifest) {
            if ($id !== 'faq') {
                $choices[$id] = $manifest->name;
            }
        }

        return $choices;
    }

    private function notFound(): void
    {
        http_response_code(404);
        $this->render('error.twig', ['status' => 404, 'message_key' => 'core.error.not_found']);
    }
}
