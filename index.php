<?php
// Deployed at /var/www/dynamo-notes/index.php on matomo-lab4 (EC2), served on port 8081
// via a dedicated Nginx site + PHP-FPM pool, separate from Matomo. No AWS credentials appear
// anywhere in this file - DynamoDbClient uses the SDK's default credential provider chain,
// which resolves to the EC2 instance's attached IAM role (matomo-dynamodb-role) via IMDSv2.

require __DIR__ . '/vendor/autoload.php';

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;

$client = new DynamoDbClient([
    'region'  => 'ap-south-1',
    'version' => 'latest',
]);
$marshaler = new Marshaler();
$table = 'matomo-site-notes';

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $item = [
        'site_id'    => (int) $_POST['site_id'],
        'note_id'    => uniqid('note_', true),
        'title'      => $_POST['title'],
        'body'       => $_POST['body'],
        'created_at' => time(),
        'resolved'   => false,
        'tags'       => array_values(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')))),
        'meta'       => [
            'author'   => $_POST['author'] ?: 'unknown',
            'priority' => $_POST['priority'] ?: 'normal',
        ],
    ];
    $client->putItem([
        'TableName' => $table,
        'Item'      => $marshaler->marshalItem($item),
    ]);
    header('Location: /');
    exit;
}

if ($action === 'resolve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $client->updateItem([
        'TableName' => $table,
        'Key' => $marshaler->marshalItem([
            'site_id' => (int) $_POST['site_id'],
            'note_id' => $_POST['note_id'],
        ]),
        'UpdateExpression' => 'SET resolved = :r',
        'ExpressionAttributeValues' => $marshaler->marshalItem([':r' => true]),
    ]);
    header('Location: /');
    exit;
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $client->deleteItem([
        'TableName' => $table,
        'Key' => $marshaler->marshalItem([
            'site_id' => (int) $_POST['site_id'],
            'note_id' => $_POST['note_id'],
        ]),
    ]);
    header('Location: /');
    exit;
}

$result = $client->scan(['TableName' => $table]);
$notes = array_map(fn($i) => $marshaler->unmarshalItem($i), $result['Items']);
usort($notes, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
?>
<!doctype html>
<title>Site Notes - DynamoDB</title>
<style>
  body{font-family:sans-serif;max-width:700px;margin:40px auto;padding:0 16px}
  .note{border:1px solid #ccc;border-radius:6px;padding:12px;margin-bottom:12px}
  .resolved{opacity:0.5;text-decoration:line-through}
  form.inline{display:inline}
  label{display:block;margin:8px 0}
  input,textarea{width:100%;box-sizing:border-box;padding:6px}
</style>
<h1>Site Operational Notes</h1>
<p>Backed by DynamoDB table <code><?= htmlspecialchars($table) ?></code> - authenticated via the EC2 instance's IAM role, no hardcoded AWS keys.</p>

<h2>Add a note</h2>
<form method="post" action="?action=create">
  <label>Site ID (number) <input name="site_id" type="number" required></label>
  <label>Title <input name="title" required></label>
  <label>Body <textarea name="body" required></textarea></label>
  <label>Tags, comma-separated <input name="tags" placeholder="urgent, billing"></label>
  <label>Priority <input name="priority" value="normal"></label>
  <label>Author <input name="author" value="calista"></label>
  <button type="submit">Create note</button>
</form>

<h2>Notes (<?= count($notes) ?>)</h2>
<?php foreach ($notes as $n): ?>
  <div class="note <?= $n['resolved'] ? 'resolved' : '' ?>">
    <strong><?= htmlspecialchars($n['title']) ?></strong> - site <?= (int) $n['site_id'] ?><br>
    <?= nl2br(htmlspecialchars($n['body'])) ?><br>
    <small>
      created <?= date('Y-m-d H:i', (int) $n['created_at']) ?> |
      priority: <?= htmlspecialchars($n['meta']['priority'] ?? '-') ?> |
      author: <?= htmlspecialchars($n['meta']['author'] ?? '-') ?> |
      tags: <?= htmlspecialchars(implode(', ', $n['tags'] ?? [])) ?> |
      resolved: <?= $n['resolved'] ? 'yes' : 'no' ?>
    </small>
    <div>
      <?php if (!$n['resolved']): ?>
      <form class="inline" method="post" action="?action=resolve">
        <input type="hidden" name="site_id" value="<?= (int) $n['site_id'] ?>">
        <input type="hidden" name="note_id" value="<?= htmlspecialchars($n['note_id']) ?>">
        <button type="submit">Mark resolved</button>
      </form>
      <?php endif; ?>
      <form class="inline" method="post" action="?action=delete" onsubmit="return confirm('Delete this note?');">
        <input type="hidden" name="site_id" value="<?= (int) $n['site_id'] ?>">
        <input type="hidden" name="note_id" value="<?= htmlspecialchars($n['note_id']) ?>">
        <button type="submit">Delete</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>
