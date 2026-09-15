<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/AssetSystem.php';

$state = AssetSystem::load();
$csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(16));
$_SESSION['csrf_token'] = $csrfToken;
$message = $_SESSION['message'] ?? null;
unset($_SESSION['message']);

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function redirect(): never { header('Location: index.php'); exit; }
function currentUser(array $state): ?array { return isset($_SESSION['user_id']) ? AssetSystem::user($state, $_SESSION['user_id']) : null; }
function flash(string $message, string $type = 'success'): void { $_SESSION['message'] = ['text' => $message, 'type' => $type]; }
function requireCsrf(string $token): void
{
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) throw new RuntimeException('Invalid security token. Refresh the page and try again.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf((string) ($_POST['csrf_token'] ?? ''));
        $action = $_POST['action'] ?? '';
        if ($action === 'login') {
            $_SESSION['user_id'] = (string) ($_POST['user_id'] ?? '');
            flash('Logged in successfully.');
        } elseif ($action === 'logout') {
            session_destroy();
            header('Location: index.php');
            exit;
        } else {
            $user = currentUser($state);
            if (!$user) throw new RuntimeException('Please log in first.');
            if ($action === 'request') AssetSystem::submitRequest($state, (string) $_POST['equipment_id'], $user['id'], (int) $_POST['days'], trim((string) $_POST['notes']));
            elseif (in_array($action, ['approve', 'reject'], true)) {
                if ($user['role'] !== AssetSystem::ADMIN) throw new RuntimeException('Access denied: Administrator only.');
                AssetSystem::approve($state, (string) $_POST['request_id'], $user['id'], $action === 'approve' ? 'Approved' : 'Rejected');
            } elseif (in_array($action, ['release', 'return', 'close'], true)) AssetSystem::transition($state, (string) $_POST['request_id'], $user['id'], $action, !empty($_POST['damaged']));
            elseif ($action === 'maintenance') AssetSystem::maintenance($state, (string) $_POST['equipment_id'], $user['id'], trim((string) $_POST['description']));
            elseif ($action === 'add_equipment') AssetSystem::addEquipment($state, $user['id'], (string) $_POST['code'], (string) $_POST['name'], (string) $_POST['location']);
            elseif ($action === 'add_user') AssetSystem::addUser($state, $user['id'], (string) $_POST['name'], (string) $_POST['role']);
            else throw new RuntimeException('Unknown action.');
            AssetSystem::save($state);
            flash('Action completed successfully.');
        }
    } catch (Throwable $error) {
        flash($error->getMessage(), 'error');
    }
    redirect();
}

$user = currentUser($state);
$page = $_GET['page'] ?? 'dashboard';
if (!$user) $page = 'login';
if ($user && !AssetSystem::canAccess($user['role'], $page)) { flash('Access denied for your role.', 'error'); $page = 'dashboard'; }
$available = array_filter($state['equipment'], fn(array $item): bool => $item['status'] === 'Available');
$visibleRequests = $user && $user['role'] === AssetSystem::REQUESTER
    ? array_filter($state['requests'], fn(array $item): bool => $item['requesterId'] === $user['id'])
    : $state['requests'];
$state['requests'] = $visibleRequests;
ob_start(function (string $html) use ($csrfToken): string {
    return preg_replace_callback('/<form(?=[^>]*method="post")[^>]*>/', static function (array $match) use ($csrfToken): string {
        return $match[0] . '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '">';
    }, $html) ?? $html;
});
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Laboratory Asset Governance</title><link rel="stylesheet" href="styles.css"></head>
<body>
<header class="topbar"><h1>Laboratory Asset and Service Management System</h1><?php if ($user): ?><div class="user-block"><span><?= h($user['name']) ?> &bull; <?= h($user['role']) ?></span><form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="logout"><button class="ghost-btn">Logout</button></form></div><?php endif; ?></header>
<main>
<?php if (!$user): ?>
<section class="card login-card"><h2>Login</h2><form method="post" class="stacked-form"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="login"><label for="user_id">Select user role</label><select id="user_id" name="user_id"><?php foreach ($state['users'] as $account): ?><option value="<?= h($account['id']) ?>"><?= h($account['name']) ?> (<?= h($account['role']) ?>)</option><?php endforeach; ?></select><button>Login</button></form></section>
<?php else: ?>
<nav class="nav-tabs"><?php foreach (['dashboard' => 'Dashboard', 'equipment' => 'Equipment', 'requests' => 'Borrowing Requests', 'maintenance' => 'Maintenance', 'audit' => 'Audit Logs', 'users' => 'User Admin', 'history' => 'My History'] as $key => $label): ?><?php if (AssetSystem::canAccess($user['role'], $key)): ?><a class="nav-btn" href="?page=<?= $key ?>"><?= $label ?></a><?php endif; ?><?php endforeach; ?></nav>
<?php if ($message): ?><div class="status-line" data-type="<?= h($message['type']) ?>"><?= h($message['text']) ?></div><?php endif; ?>
<?php if ($page === 'dashboard'): ?><section class="page-panel"><div class="summary-grid"><div class="summary-card"><h3>Active Requests</h3><p><?= count(array_filter($state['requests'], fn(array $item): bool => !in_array($item['status'], ['Closed', 'Returned'], true))) ?></p></div><div class="summary-card"><h3>Available Equipment</h3><p><?= count($available) ?></p></div><div class="summary-card"><h3>Audit Entries</h3><p><?= count($state['auditLogs']) ?></p></div></div></section><?php if (in_array($user['role'], [AssetSystem::ADMIN, AssetSystem::STAFF], true)): ?><section class="page-panel"><h2>Transaction Controls</h2><?php foreach ($state['requests'] as $request): ?><?php if (in_array($request['status'], ['Released', 'Overdue'], true)): ?><form class="inline-form" method="post"><input type="hidden" name="request_id" value="<?= h($request['id']) ?>"><button name="action" value="return">Return</button><label><input type="checkbox" name="damaged" value="1"> Damaged</label></form><?php elseif ($request['status'] === 'Returned'): ?><form class="inline-form" method="post"><input type="hidden" name="request_id" value="<?= h($request['id']) ?>"><button name="action" value="close">Close <?= h($request['id']) ?></button></form><?php endif; ?><?php endforeach; ?></section><?php endif; ?>
<?php elseif ($page === 'equipment'): ?><section class="page-panel"><h2>Equipment Inventory</h2><?php if ($user['role'] === AssetSystem::ADMIN): ?><form method="post" class="stacked-form"><input type="hidden" name="action" value="add_equipment"><input name="code" placeholder="Asset code" required><input name="name" placeholder="Equipment name" required><input name="location" placeholder="Location" required><button>Add Equipment</button></form><?php endif; ?><table><thead><tr><th>Code</th><th>Name</th><th>Status</th><th>Location</th><th>Condition</th></tr></thead><tbody><?php foreach ($state['equipment'] as $item): ?><tr><td><?= h($item['code']) ?></td><td><?= h($item['name']) ?></td><td><?= h($item['status']) ?></td><td><?= h($item['location']) ?></td><td><?= h($item['condition']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php elseif ($page === 'requests'): ?><section class="page-panel"><h2>Borrowing Requests</h2><form method="post" class="stacked-form"><input type="hidden" name="action" value="request"><label>Equipment</label><select name="equipment_id" required><?php foreach ($available as $item): ?><option value="<?= h($item['id']) ?>"><?= h($item['code']) ?> - <?= h($item['name']) ?></option><?php endforeach; ?></select><label>Days requested</label><input name="days" type="number" min="1" max="30" value="2"><label>Notes</label><textarea name="notes" rows="3"></textarea><button>Submit Borrowing Request</button></form><table><thead><tr><th>ID</th><th>Equipment</th><th>Requester</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($state['requests'] as $request): $equipment = AssetSystem::equipment($state, $request['equipmentId']); $requester = AssetSystem::user($state, $request['requesterId']); ?><tr><td><?= h($request['id']) ?></td><td><?= h($equipment['code'] ?? $request['equipmentId']) ?></td><td><?= h($requester['name'] ?? $request['requesterId']) ?></td><td><?= h($request['status']) ?></td><td><?php if ($request['status'] === 'Pending' && $user['role'] === AssetSystem::ADMIN): ?><form class="inline-form" method="post"><input type="hidden" name="request_id" value="<?= h($request['id']) ?>"><button name="action" value="approve">Approve</button><button class="danger" name="action" value="reject">Reject</button></form><?php elseif ($request['status'] === 'Approved' && $user['role'] !== AssetSystem::REQUESTER): ?><form class="inline-form" method="post"><input type="hidden" name="request_id" value="<?= h($request['id']) ?>"><button name="action" value="release">Release</button></form><?php elseif (in_array($request['status'], ['Released', 'Overdue'], true) && $user['role'] !== AssetSystem::REQUESTER): ?><form class="inline-form" method="post"><input type="hidden" name="request_id" value="<?= h($request['id']) ?>"><button name="action" value="return">Return</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php elseif ($page === 'maintenance'): ?><section class="page-panel"><h2>Maintenance Requests</h2><form method="post" class="stacked-form"><input type="hidden" name="action" value="maintenance"><label>Equipment</label><select name="equipment_id"><?php foreach ($state['equipment'] as $item): ?><option value="<?= h($item['id']) ?>"><?= h($item['code']) ?> - <?= h($item['name']) ?></option><?php endforeach; ?></select><label>Description</label><textarea name="description" rows="4" required></textarea><button>Submit Maintenance Request</button></form><table><thead><tr><th>ID</th><th>Equipment</th><th>Status</th><th>Description</th></tr></thead><tbody><?php foreach ($state['maintenance'] as $item): ?><tr><td><?= h($item['id']) ?></td><td><?= h($item['equipmentId']) ?></td><td><?= h($item['status']) ?></td><td><?= h($item['description']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php elseif ($page === 'audit'): ?><section class="page-panel"><h2>Audit Logs</h2><table><thead><tr><th>Action</th><th>Module</th><th>Record ID</th><th>Description</th><th>Time</th></tr></thead><tbody><?php foreach (array_slice($state['auditLogs'], 0, 20) as $entry): ?><tr><td><?= h($entry['action']) ?></td><td><?= h($entry['module']) ?></td><td><?= h($entry['recordId']) ?></td><td><?= h($entry['description']) ?></td><td><?= h($entry['createdAt']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php elseif ($page === 'users'): ?><section class="page-panel"><h2>User Administration</h2><form method="post" class="stacked-form"><input type="hidden" name="action" value="add_user"><input name="name" placeholder="Full name" required><select name="role"><option><?= h(AssetSystem::STAFF) ?></option><option><?= h(AssetSystem::REQUESTER) ?></option><option><?= h(AssetSystem::ADMIN) ?></option></select><button>Add User</button></form><table><thead><tr><th>ID</th><th>Name</th><th>Role</th></tr></thead><tbody><?php foreach ($state['users'] as $account): ?><tr><td><?= h($account['id']) ?></td><td><?= h($account['name']) ?></td><td><?= h($account['role']) ?></td></tr><?php endforeach; ?></tbody></table></section>
<?php elseif ($page === 'history'): ?><section class="page-panel"><h2>My Borrowing History</h2><ul><?php foreach (array_filter($state['requests'], fn(array $item): bool => $item['requesterId'] === $user['id']) as $request): ?><li><?= h($request['id']) ?> &mdash; <?= h($request['status']) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php endif; ?></main></body></html>