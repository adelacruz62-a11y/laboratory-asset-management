<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/AssetSystem.php';

function check(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException("FAIL: {$name}");
    echo "PASS: {$name}\n";
}

$state = AssetSystem::initialState();
check(!AssetSystem::canAccess(AssetSystem::REQUESTER, 'users'), 'TC-A4-01 requester cannot open admin page');

AssetSystem::submitRequest($state, 'EQ-002', 'U-002', 2, 'Staff request');
$request = $state['requests'][count($state['requests']) - 1];
check($request['status'] === 'Pending', 'TC-A4-02 request starts Pending');

AssetSystem::approve($state, $request['id'], 'U-001', 'Approved');
check($state['requests'][count($state['requests']) - 1]['status'] === 'Approved', 'TC-A4-03 administrator approval works');
check($state['auditLogs'][0]['action'] === 'APPROVED', 'TC-A4-03 approval creates audit log');

AssetSystem::transition($state, $request['id'], 'U-002', 'release');
check($state['requests'][count($state['requests']) - 1]['status'] === 'Released', 'TC-A4-06 approved request can be released');
check(AssetSystem::equipment($state, 'EQ-002')['status'] === 'Borrowed', 'TC-A4-06 release marks equipment Borrowed');

AssetSystem::transition($state, $request['id'], 'U-002', 'return');
check($state['requests'][count($state['requests']) - 1]['status'] === 'Returned', 'TC-A4-07 released request can be returned');
check(AssetSystem::equipment($state, 'EQ-002')['status'] === 'Available', 'TC-A4-07 return marks equipment Available');
AssetSystem::transition($state, $request['id'], 'U-002', 'close');
check($state['requests'][count($state['requests']) - 1]['status'] === 'Closed', 'Returned request can be closed');

$damagedState = AssetSystem::initialState();
AssetSystem::submitRequest($damagedState, 'EQ-004', 'U-003', 1, 'Damaged return test');
$damagedRequest = $damagedState['requests'][count($damagedState['requests']) - 1];
AssetSystem::approve($damagedState, $damagedRequest['id'], 'U-001', 'Approved');
AssetSystem::transition($damagedState, $damagedRequest['id'], 'U-002', 'release');
AssetSystem::transition($damagedState, $damagedRequest['id'], 'U-002', 'return', true);
check(AssetSystem::equipment($damagedState, 'EQ-004')['status'] === 'Damaged', 'Damaged return marks equipment Damaged');

$adminState = AssetSystem::initialState();
AssetSystem::addEquipment($adminState, 'U-001', 'CAM-100', 'Lab Camera', 'Room C-1');
AssetSystem::addUser($adminState, 'U-001', 'New Staff', AssetSystem::STAFF);
check(count($adminState['equipment']) === 5 && count($adminState['users']) === 5, 'Administrator can add equipment and users');

$rejectedState = AssetSystem::initialState();
AssetSystem::approve($rejectedState, 'BR-101', 'U-001', 'Rejected');
check($rejectedState['requests'][0]['status'] === 'Rejected', 'TC-A4-04 administrator can reject');
try {
    AssetSystem::transition($rejectedState, 'BR-101', 'U-001', 'release');
    throw new RuntimeException('Rejected request was released');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Only Approved'), 'TC-A4-05 rejected request cannot be released');
}

try {
    AssetSystem::maintenance($state, 'EQ-001', 'U-003', 'Requester attempt');
    throw new RuntimeException('Requester submitted maintenance');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Access denied'), 'TC-A4-09 requester cannot submit staff maintenance action');
}

try {
    AssetSystem::transition($state, $request['id'], 'U-003', 'return');
    throw new RuntimeException('Requester processed return');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Access denied'), 'TC-A4-10 requester cannot process return');
}

echo "All PHP workflow tests passed.\n";