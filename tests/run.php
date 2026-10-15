<?php

declare(strict_types=1);

// Plain-PHP checks of the FAQ extension's own classes, on SQLite in memory:
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// The core is only needed for its classes (vendor/autoload.php); without
// the variable it is expected next to this repository, in ../modulento.
// Exit code 0 means every check passed.

use Modulento\Core\App;
use Modulento\Faq\AdminController;
use Modulento\Faq\Faq;

$here = dirname(__DIR__);
$core = getenv('MODULENTO_CORE') ?: dirname($here) . '/modulento';

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "No Modulento core with vendor/ found at {$core}.\n"
        . "Set MODULENTO_CORE to a working copy of the core in which \"composer install\" has run.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$failed = [];
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        $failed[] = $label;
    }
}

// --- Database ------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER, enabled_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('faq', '0', 1, '2026-01-01 00:00:00')");
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT DEFAULT 'active',
    locale TEXT DEFAULT 'de', created_at TEXT, last_login_at TEXT)");
$pdo->exec('CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT, in_header INTEGER, in_footer INTEGER, position INTEGER)');
$pdo->exec('CREATE TABLE page_translation (page_id INTEGER, locale TEXT, title TEXT, slug TEXT, meta_description TEXT, body TEXT)');
$pdo->exec('CREATE TABLE account_preference (account_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (account_id, name))');
$pdo->exec('CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)');
$pdo->exec('CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)');
$pdo->exec('CREATE TABLE x_faq_item (id INTEGER PRIMARY KEY, extension_id TEXT, position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE x_faq_item_translation (item_id INTEGER NOT NULL REFERENCES x_faq_item (id) ON DELETE CASCADE,
    locale TEXT NOT NULL, question TEXT NOT NULL, answer TEXT NOT NULL, PRIMARY KEY (item_id, locale))');

// The migration and the schema above must name the same columns.
foreach (['x_faq_item' => '001_faq.sql', 'x_faq_item_translation' => '001_faq.sql'] as $table => $file) {
    $migration = (string) file_get_contents($here . '/migrations/' . $file);
    preg_match('/CREATE TABLE ' . $table . ' \((.*?)\n\) ENGINE/s', $migration, $body);
    preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
    $inTest = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(), 'name');
    sort($columns[1]);
    sort($inTest);
    check("schema: {$table} has the same columns in the migration and in the tests", $columns[1] !== [] && $columns[1] === $inTest);
}

$uploads = sys_get_temp_dir() . '/modulento-faq-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
symlink($here, $uploads . '/extensions/faq');

$app = new App([
    'app' => [
        'env' => 'dev', 'url' => 'https://faq.example', 'name' => 'Testseite', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
    ],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
], $pdo, 'de');
$app->translator->load($core . '/core/lang', 'core');
$app->extensions->loadEnabled($app);
check('extension: the core loads it', isset($app->extensions->loaded()['faq']) && $app->extensions->loaded()['faq']->type === 'addon');
check('extension: permission, admin menu entry and footer link are announced', ($app->permissions()['faq.items.manage'] ?? null) === 'faq.permission.items_manage'
    && $app->adminMenu() === [['label_key' => 'faq.admin.menu', 'path' => '/admin/faq', 'permission' => 'faq.items.manage', 'group' => 'content']]
    && $app->footerLinks() === [['label_key' => 'faq.footer_link', 'path' => '/faq']]
    && $app->navigation() === []);

// --- Saving, finding, deleting ---------------------------------------------
$faq = new Faq($pdo);

$result = $faq->save(null, null, 5, ['de' => ['question' => 'Wie bezahle ich?', 'answer' => '<p>Per Überweisung.</p>'], 'en' => ['question' => '', 'answer' => '']]);
check('save: a general entry with only one language filled in', $result['errors'] === [] && $result['id'] !== null);
$general = $faq->find($result['id']);
check('find: stores only the language that was actually filled in', $general['texts'] === ['de' => ['question' => 'Wie bezahle ich?', 'answer' => '<p>Per Überweisung.</p>']] && $general['extension_id'] === null);

check('save: without a question or an answer in any language, nothing is saved', $faq->save(null, null, 0, ['de' => ['question' => 'Nur Frage', 'answer' => '']])['errors'] === ['faq.admin.error.no_text']);

$shop = $faq->save(null, 'shop', 1, ['de' => ['question' => 'Versandkosten?', 'answer' => 'Ab 50€ kostenlos.']]);
$freelancer = $faq->save(null, 'freelancer', 1, ['de' => ['question' => 'Wie läuft ein Auftrag ab?', 'answer' => 'Schritt für Schritt.']]);
check('save: assigned to the extension given', $faq->find($shop['id'])['extension_id'] === 'shop' && $faq->find($freelancer['id'])['extension_id'] === 'freelancer');

$edited = $faq->save($shop['id'], 'shop', 2, ['de' => ['question' => 'Versandkosten (neu)?', 'answer' => 'Ab 30€ kostenlos.']]);
check('save: editing keeps the same id, replaces the texts', $edited['id'] === $shop['id'] && $faq->find($shop['id'])['texts']['de']['question'] === 'Versandkosten (neu)?'
    && count($faq->find($shop['id'])['texts']) === 1);

check('clean: a script in the answer is removed, harmless formatting stays', (function () use ($faq): bool {
    $r = $faq->save(null, null, 0, ['de' => ['question' => 'XSS?', 'answer' => '<p>Sicher<script>alert(1)</script></p>']]);
    $stored = $faq->find($r['id'])['texts']['de']['answer'];
    $faq->delete($r['id']);
    return $stored === '<p>Sicher</p>';
})());

$faq->delete($general['id']);
check('delete: the entry and its texts are gone', $faq->find($general['id']) === null
    && (int) $pdo->query('SELECT COUNT(*) FROM x_faq_item_translation WHERE item_id = ' . $general['id'])->fetchColumn() === 0);

// --- Listing for the administration: filtered by extension, paged ----------
check('listAll: "all" (null) lists every entry', $faq->listAll(null, 1, 50)['total'] === 2);
check('listAll: filtered to one extension', $faq->listAll('shop', 1, 50)['total'] === 1 && $faq->listAll('freelancer', 1, 50)['total'] === 1);
check('listAll: "" lists only the general ones', $faq->listAll('', 1, 50)['total'] === 0);
$page1 = $faq->listAll(null, 1, 1);
check('listAll: paging works', count($page1['rows']) === 1 && $page1['total'] === 2);

// --- Visible to the public: by enabled extension, by language, by search ---
$general2 = $faq->save(null, null, 0, [
    'de' => ['question' => 'Allgemeine Frage', 'answer' => 'Für alle sichtbar.'],
    'en' => ['question' => 'General question', 'answer' => 'Visible to everyone.'],
]);
check('save: an entry with both languages filled in', $general2['errors'] === [] && $general2['id'] !== null);
check('listVisible: a general entry shows regardless of what is enabled', array_column($faq->listVisible('de', 'de', []), 'id') === [$general2['id']]);
check('listVisible: an extension-assigned entry is hidden while that extension is not enabled', array_column($faq->listVisible('de', 'de', ['freelancer']), 'id') === [$freelancer['id'], $general2['id']]
    || array_column($faq->listVisible('de', 'de', ['freelancer']), 'id') === [$general2['id'], $freelancer['id']]);
check('listVisible: with neither shop nor freelancer enabled, neither of their questions show', !in_array($shop['id'], array_column($faq->listVisible('de', 'de', []), 'id'), true)
    && !in_array($freelancer['id'], array_column($faq->listVisible('de', 'de', []), 'id'), true));
check('listVisible: enabling shop shows shop\'s question, not freelancer\'s - "damit nicht FAQ von freelancer in der Erweiterung shop angezeigt werden"',
    in_array($shop['id'], array_column($faq->listVisible('de', 'de', ['shop']), 'id'), true)
    && !in_array($freelancer['id'], array_column($faq->listVisible('de', 'de', ['shop']), 'id'), true));

$withEnglish = $faq->listVisible('en', 'de', []);
check('listVisible: the one entry with an English text is shown in English', $withEnglish[0]['question'] === 'General question');

check('search: matches the question', array_column($faq->listVisible('de', 'de', ['shop'], 'Versandkosten'), 'id') === [$shop['id']]);
check('search: matches the answer too, case-insensitively', array_column($faq->listVisible('de', 'de', ['shop'], 'KOSTENLOS'), 'id') === [$shop['id']]);
check('search: no match, nothing shown', $faq->listVisible('de', 'de', ['shop'], 'nichts davon') === []);
check('search: empty search shows everything again', count($faq->listVisible('de', 'de', ['shop'])) === 2);

// --- AdminController::save(): validation, assignment limited to real extensions ---
$_POST = ['extension_id' => 'not-a-real-extension', 'position' => '3', 'texts' => ['de' => ['question' => 'Zugeordnet?', 'answer' => 'Ja.']]];
(new AdminController($app))->save([]);
$lastId = (int) $pdo->query('SELECT MAX(id) FROM x_faq_item')->fetchColumn();
check('AdminController::save(): an id that names no discovered extension is not stored, the entry still saves as general',
    $faq->find($lastId)['extension_id'] === null);
$faq->delete($lastId);

// --- Clean up --------------------------------------------------------------
$remove = function (string $dir) use (&$remove): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
};
$remove($uploads);

// --- Language files ----------------------------------------------------------
$de = require $here . '/lang/de.php';
$en = require $here . '/lang/en.php';
check('lang: de and en have the same keys', array_keys($de) === array_keys($en));
check('lang: every key starts with "faq."', array_filter(array_keys($de), fn (string $key) => !str_starts_with($key, 'faq.')) === []);
$used = [];
foreach ([...glob($here . '/templates/{,*/}*.twig', GLOB_BRACE), ...glob($here . '/src/*.php')] as $file) {
    preg_match_all("/'(faq\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    $used = array_merge($used, $found[1]);
}
$unknown = array_diff(array_unique($used), array_keys($de), ['faq.items.manage']);
check('lang: every key used in templates and code exists: ' . implode(', ', $unknown), $unknown === []);

if ($failed !== []) {
    echo "FAILED:\n";
    foreach ($failed as $label) {
        echo "  - {$label}\n";
    }
    echo count($failed) . " of {$checks} checks failed\n";
    exit(1);
}

echo "OK ({$checks} checks)\n";
