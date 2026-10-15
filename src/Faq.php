<?php

declare(strict_types=1);

namespace Modulento\Faq;

use Modulento\Core\Support\Clock;
use Modulento\Core\Support\HtmlSanitizer;
use PDO;

/**
 * Questions and answers, each either general (shown regardless of what is
 * installed) or belonging to one installed extension (shown only while
 * that extension is enabled) - so an operator who runs one marketplace
 * extension after another is never shown another one's questions.
 */
final class Faq
{
    private const MAX_QUESTION = 300;
    private const MAX_ANSWER = 20000;

    public function __construct(private PDO $db)
    {
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} every entry, for the administration; $extensionId null for every one, '' for only the general ones */
    public function listAll(?string $extensionId, int $page, int $perPage): array
    {
        $where = '';
        $params = [];
        if ($extensionId !== null) {
            $where = $extensionId === '' ? 'WHERE extension_id IS NULL' : 'WHERE extension_id = :extension_id';
            $params = $extensionId === '' ? [] : ['extension_id' => $extensionId];
        }

        $count = $this->db->prepare("SELECT COUNT(*) FROM x_faq_item {$where}");
        $count->execute($params);

        $stmt = $this->db->prepare(
            "SELECT id FROM x_faq_item {$where} ORDER BY extension_id IS NOT NULL, extension_id, position, id
             LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, (min($page, 100000) - 1) * $perPage)
        );
        $stmt->execute($params);

        return [
            'rows' => array_map(fn (int $id) => $this->find($id), $stmt->fetchAll(PDO::FETCH_COLUMN)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_faq_item WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM x_faq_item_translation WHERE item_id = :id');
        $stmt->execute(['id' => $id]);
        $texts = [];
        foreach ($stmt->fetchAll() as $text) {
            $texts[$text['locale']] = ['question' => $text['question'], 'answer' => $text['answer']];
        }

        return [
            'id' => (int) $row['id'],
            'extension_id' => $row['extension_id'],
            'position' => (int) $row['position'],
            'texts' => $texts,
        ];
    }

    /**
     * @param array<string, array{question: string, answer: string}> $texts locale => fields, as typed
     * @return array{id: ?int, errors: list<string>}
     */
    public function save(?int $id, ?string $extensionId, int $position, array $texts): array
    {
        $clean = [];
        foreach ($texts as $locale => $text) {
            $question = trim(mb_substr((string) ($text['question'] ?? ''), 0, self::MAX_QUESTION));
            $answer = HtmlSanitizer::clean(mb_substr((string) ($text['answer'] ?? ''), 0, self::MAX_ANSWER));
            if ($question !== '' && $answer !== '') {
                $clean[$locale] = ['question' => $question, 'answer' => $answer];
            }
        }
        if ($clean === []) {
            return ['id' => null, 'errors' => ['faq.admin.error.no_text']];
        }

        $now = Clock::now();
        if ($id === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO x_faq_item (extension_id, position, created_at, updated_at) VALUES (:extension_id, :position, :now, :now)'
            );
            $stmt->execute(['extension_id' => $extensionId, 'position' => $position, 'now' => $now]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $stmt = $this->db->prepare('UPDATE x_faq_item SET extension_id = :extension_id, position = :position, updated_at = :now WHERE id = :id');
            $stmt->execute(['extension_id' => $extensionId, 'position' => $position, 'now' => $now, 'id' => $id]);
            $this->db->prepare('DELETE FROM x_faq_item_translation WHERE item_id = :id')->execute(['id' => $id]);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO x_faq_item_translation (item_id, locale, question, answer) VALUES (:id, :locale, :question, :answer)'
        );
        foreach ($clean as $locale => $text) {
            $stmt->execute(['id' => $id, 'locale' => $locale, 'question' => $text['question'], 'answer' => $text['answer']]);
        }

        return ['id' => $id, 'errors' => []];
    }

    public function delete(int $id): void
    {
        $this->db->prepare('DELETE FROM x_faq_item WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Public entries: general ones and ones belonging to a currently
     * enabled extension, each in the visitor's language where there is
     * one, else the site's default language. $search, when given, keeps
     * only entries whose question or answer (in the language shown)
     * contains it.
     *
     * @param string[] $enabledExtensionIds
     * @return list<array{id: int, question: string, answer: string}>
     */
    public function listVisible(string $locale, string $defaultLocale, array $enabledExtensionIds, string $search = ''): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, i.extension_id, i.position FROM x_faq_item_translation t
             JOIN x_faq_item i ON i.id = t.item_id
             WHERE t.locale = :locale'
        );
        $stmt->execute(['locale' => $locale]);
        $byLocale = [];
        foreach ($stmt->fetchAll() as $row) {
            $byLocale[(int) $row['item_id']] = $row;
        }

        if ($locale !== $defaultLocale) {
            $stmt = $this->db->prepare(
                'SELECT t.*, i.extension_id, i.position FROM x_faq_item_translation t
                 JOIN x_faq_item i ON i.id = t.item_id
                 WHERE t.locale = :locale AND t.item_id NOT IN (' . (implode(',', array_keys($byLocale)) ?: '0') . ')'
            );
            $stmt->execute(['locale' => $defaultLocale]);
            foreach ($stmt->fetchAll() as $row) {
                $byLocale[(int) $row['item_id']] = $row;
            }
        }

        $search = mb_strtolower(trim($search));
        $visible = [];
        foreach ($byLocale as $itemId => $row) {
            $extensionId = $row['extension_id'];
            if ($extensionId !== null && !in_array($extensionId, $enabledExtensionIds, true)) {
                continue;
            }
            if ($search !== '' && !str_contains(mb_strtolower($row['question']), $search) && !str_contains(mb_strtolower($row['answer']), $search)) {
                continue;
            }
            $visible[] = [
                'id' => $itemId,
                'extension_id' => $extensionId,
                'position' => (int) $row['position'],
                'question' => $row['question'],
                'answer' => $row['answer'],
            ];
        }

        usort($visible, fn (array $a, array $b) => [$a['extension_id'] !== null, $a['extension_id'], $a['position'], $a['id']]
            <=> [$b['extension_id'] !== null, $b['extension_id'], $b['position'], $b['id']]);

        return $visible;
    }
}
